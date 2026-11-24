<?php
declare(strict_types=1);

/**
 * User account management (admin).
 *  - Creating and editing accounts (collector/admin role, activation, password reset).
 *  - Passwords are hashed (password_hash); the hash is NEVER returned by the API.
 *  - Safeguards: you cannot disable the last active admin, nor yourself.
 *
 * "Deleting" an account = deactivation (is_active = 0); accounts are never physically deleted because of
 * foreign keys (features.created_by/updated_by) and to preserve authorship.
 */
final class UserService
{
    private const ROLES = ['collector', 'admin'];
    private const COLS = 'id, username, email, display_name, role, is_active, created_at, last_login_at';

    /** List of accounts for admin management (without passwords). */
    public function getAll(): array
    {
        $rows = Database::pdo()
            ->query('SELECT ' . self::COLS . ' FROM users ORDER BY is_active DESC, username ASC')
            ->fetchAll();
        return array_map([$this, 'shape'], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT ' . self::COLS . ' FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $this->shape($row) : null;
    }

    public function create(array $in): int
    {
        $username = trim((string) ($in['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
            $this->fail('username_format', 'Username: 3-64 characters (letters, digits, _ . -).');
        }
        $displayName = trim((string) ($in['display_name'] ?? ''));
        if ($displayName === '') {
            $this->fail('display_name_required', 'Fill in the display name.');
        }
        if (mb_strlen($displayName) > 120) {
            $this->fail('display_name_too_long', 'The display name is too long (max 120 characters).');
        }
        $password = (string) ($in['password'] ?? '');
        if (mb_strlen($password) < 8) {
            $this->fail('password_too_short', 'The password must be at least 8 characters.');
        }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('email_invalid', 'Invalid e-mail.');
        }
        $role = (string) ($in['role'] ?? 'collector');
        if (!in_array($role, self::ROLES, true)) {
            $this->fail('role_invalid', 'Invalid role.');
        }

        $this->assertUsernameFree($username);
        if ($email !== '') {
            $this->assertEmailFree($email, null);
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, display_name, role, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $username,
            $email !== '' ? $email : null,
            password_hash($password, PASSWORD_DEFAULT),
            $displayName,
            $role,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Partial account update – changes only the supplied fields. The username is immutable.
     * @param int $actingId id of the signed-in admin (safeguards against self-lockout)
     * @throws HttpException
     */
    public function update(int $id, array $in, int $actingId): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new HttpException('User not found.', 404, 'user_not_found');
        }

        $sets = [];
        $params = [];

        if (array_key_exists('display_name', $in)) {
            $dn = trim((string) $in['display_name']);
            if ($dn === '') {
                $this->fail('display_name_required', 'Fill in the display name.');
            }
            if (mb_strlen($dn) > 120) {
                $this->fail('display_name_too_long', 'The display name is too long (max 120 characters).');
            }
            $sets[] = 'display_name = ?';
            $params[] = $dn;
        }

        if (array_key_exists('email', $in)) {
            $email = trim((string) $in['email']);
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->fail('email_invalid', 'Invalid e-mail.');
                }
                $this->assertEmailFree($email, $id);
            }
            $sets[] = 'email = ?';
            $params[] = $email !== '' ? $email : null;
        }

        // Role + active flag – with safeguards against disabling the last admin / yourself.
        $newRole = array_key_exists('role', $in) ? (string) $in['role'] : $existing['role'];
        if (array_key_exists('role', $in) && !in_array($newRole, self::ROLES, true)) {
            $this->fail('role_invalid', 'Invalid role.');
        }
        if (array_key_exists('is_active', $in) && !is_bool($in['is_active'])) {
            $this->fail('active_flag_invalid', 'Invalid activation value.');
        }
        $newActive = array_key_exists('is_active', $in) ? (bool) $in['is_active'] : $existing['is_active'];

        $roleChange = array_key_exists('role', $in) && $newRole !== $existing['role'];
        $activeChange = array_key_exists('is_active', $in) && $newActive !== $existing['is_active'];

        if ($id === $actingId) {
            if ($activeChange && $newActive === false) {
                $this->fail('cannot_deactivate_self', 'You cannot deactivate your own account.');
            }
            if ($roleChange && $newRole !== 'admin') {
                $this->fail('cannot_demote_self', 'You cannot remove your own administrator rights.');
            }
        }
        if ($roleChange) {
            $sets[] = 'role = ?';
            $params[] = $newRole;
        }
        if ($activeChange) {
            $sets[] = 'is_active = ?';
            $params[] = $newActive ? 1 : 0;
        }

        if (array_key_exists('password', $in) && (string) $in['password'] !== '') {
            $password = (string) $in['password'];
            if (mb_strlen($password) < 8) {
                $this->fail('password_too_short', 'The password must be at least 8 characters.');
            }
            $sets[] = 'password_hash = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }

        if (!$sets) {
            return; // nothing to change
        }
        $params[] = $id;
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $pdo = Database::pdo();

        // Are we revoking rights from / deactivating an active admin? The "last admin" check
        // must be ATOMIC – otherwise two concurrent requests could drive the admin count to zero.
        $guardLastAdmin = ($roleChange || $activeChange)
            && $existing['role'] === 'admin' && $existing['is_active']
            && ($newRole !== 'admin' || $newActive === false);

        if ($guardLastAdmin) {
            // "Last admin" check + write atomically. $this->fail() throws an exception → the helper
            // rolls back the transaction itself and propagates the error.
            Database::transaction(function (PDO $pdo) use ($sql, $params) {
                // FOR UPDATE locks the rows of active admins → concurrent requests are serialized.
                $cnt = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE")->fetchColumn();
                if ($cnt <= 1) {
                    $this->fail('last_admin', 'The last active administrator account cannot be disabled.');
                }
                $pdo->prepare($sql)->execute($params);
            });
            return;
        }

        $pdo->prepare($sql)->execute($params);
    }

    // --------------------------------------------------------- helpers -------

    private function assertUsernameFree(string $username): void
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $this->fail('username_exists', 'A user with this username already exists.');
        }
    }

    private function assertEmailFree(string $email, ?int $exceptId): void
    {
        $sql = 'SELECT id FROM users WHERE email = ?' . ($exceptId !== null ? ' AND id <> ?' : '') . ' LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($exceptId !== null ? [$email, $exceptId] : [$email]);
        if ($stmt->fetch()) {
            $this->fail('email_in_use', 'This e-mail is already used by another account.');
        }
    }

    private function shape(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'username'      => $row['username'],
            'display_name'  => $row['display_name'],
            'email'         => $row['email'],
            'role'          => $row['role'],
            'is_active'     => (int) $row['is_active'] === 1,
            'created_at'    => $row['created_at'],
            'last_login_at' => $row['last_login_at'],
        ];
    }

    private function fail(string $code, string $message, array $params = []): void
    {
        throw new HttpException($message, 422, $code, [], $params);
    }
}

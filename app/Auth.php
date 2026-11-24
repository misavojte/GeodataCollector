<?php
declare(strict_types=1);

/**
 * Authentication and authorization.
 *  - Accounts are created by an administrator (no public registration).
 *  - Simple login attempt throttling against brute force.
 *  - Roles: collector / admin (the admin module is a future extension).
 */
final class Auth
{
    /** false = not loaded yet; null = not signed in; array = user */
    private static $cachedUser = false;

    public static function currentUser(): ?array
    {
        if (self::$cachedUser !== false) {
            return self::$cachedUser;
        }

        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return self::$cachedUser = null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, username, email, display_name, role, is_active
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int) $id]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['is_active'] !== 1) {
            self::logout();
            return self::$cachedUser = null;
        }
        return self::$cachedUser = $user;
    }

    public static function check(): bool
    {
        return self::currentUser() !== null;
    }

    public static function requireAuth(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            self::logAuthMiss();
            throw new HttpException('Sign-in required.', 401, 'unauthorized');
        }
        return $user;
    }

    /**
     * 401 diagnostics to the error log – anomalies only, no cookie values.
     * Mainly watches for a DUPLICATE session cookie (a shadow on a narrower path or with a Domain
     * attribute): after login it would permanently shadow the fresh session and the user
     * would loop back to the login page. Also logs rejection of an expired ID by strict mode
     * (common after longer inactivity; the frequency shows what is happening in production).
     */
    private static function logAuthMiss(): void
    {
        $name = session_name();
        $raw = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
        $count = $raw === '' ? 0 : substr_count($raw, $name . '=');
        $presented = $_COOKIE[$name] ?? null;
        // In strict mode: new ID ≠ presented ID ⇒ the presented one was rejected (expired/unknown).
        $rejected = is_string($presented) && $presented !== '' && $presented !== session_id();
        if ($count > 1 || $rejected) {
            $uri = substr(preg_replace('/[^\x20-\x7E]/', '?', (string) ($_SERVER['REQUEST_URI'] ?? '')), 0, 120);
            error_log(sprintf(
                'AUTH 401: session_cookie=%s occurrences=%d rejected_strict=%s uri=%s',
                $presented === null ? 'no' : 'yes',
                $count,
                $rejected ? 'yes' : 'no',
                $uri
            ));
        }
    }

    public static function requireAdmin(): array
    {
        $user = self::requireAuth();
        if (($user['role'] ?? '') !== 'admin') {
            throw new HttpException('Administrator rights required.', 403, 'forbidden');
        }
        return $user;
    }

    public static function isAdmin(): bool
    {
        $user = self::currentUser();
        return $user !== null && ($user['role'] ?? '') === 'admin';
    }

    /**
     * Login attempt. On success, signs the user in right away and returns the user.
     * @throws HttpException on failure / throttling
     */
    public static function attemptLogin(string $username, string $password, string $ip): array
    {
        $username = trim($username);
        $maxAttempts = (int) Config::get('security.login_max_attempts', 8);
        $window = (int) Config::get('security.login_window_seconds', 900);

        // Self-maintenance: occasionally (5 %) prune old attempts so the table doesn't grow forever
        // (no cron dependency). This is the only table that untrusted traffic also fills.
        if (random_int(1, 20) === 1) {
            self::pruneLoginAttempts();
        }

        if (self::recentFailedAttempts($ip, $window) >= $maxAttempts) {
            throw new HttpException(
                'Too many failed sign-in attempts. Please try again in a few minutes.',
                429,
                'too_many_attempts'
            );
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $ok = $user
            && (int) $user['is_active'] === 1
            && password_verify($password, (string) $user['password_hash']);

        self::recordAttempt($username, $ip, (bool) $ok);

        if (!$ok) {
            throw new HttpException('Invalid username or password.', 401, 'bad_credentials');
        }

        self::loginUser($user);
        self::clearFailedAttempts($ip);
        AuditLog::record((int) $user['id'], 'auth.login', 'user', (int) $user['id'], null, $ip);
        return $user;
    }

    public static function loginUser(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$cachedUser = false;
        Database::pdo()
            ->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([(int) $user['id']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'] ?? '/',
                'domain'   => $p['domain'] ?? '',
                'secure'   => (bool) ($p['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$cachedUser = null;
    }

    // ---------------------------------------------------------- throttle ----

    /**
     * Number of recent failed attempts from the given IP. The hard block is per IP,
     * NOT per username – otherwise anyone could easily lock out someone else's account (DoS).
     */
    private static function recentFailedAttempts(string $ip, int $window): int
    {
        $window = max(1, (int) $window); // safely cast to int (comes from config)
        $sql = 'SELECT COUNT(*) FROM login_attempts
                WHERE success = 0
                  AND ip_address = ?
                  AND attempted_at > (NOW() - INTERVAL ' . $window . ' SECOND)';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([substr($ip, 0, 45)]);
        return (int) $stmt->fetchColumn();
    }

    /** After a successful login, reset the failure counter for the given IP. */
    private static function clearFailedAttempts(string $ip): void
    {
        Database::pdo()
            ->prepare('DELETE FROM login_attempts WHERE success = 0 AND ip_address = ?')
            ->execute([substr($ip, 0, 45)]);
    }

    /**
     * Deletes old login attempts (outside the throttle window) – keeps the table small
     * regardless of whether the given IP ever succeeded. Called periodically from attemptLogin.
     */
    public static function pruneLoginAttempts(): void
    {
        try {
            Database::pdo()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
        } catch (Throwable $e) {
            error_log('pruneLoginAttempts failed: ' . $e->getMessage());
        }
    }

    private static function recordAttempt(string $username, string $ip, bool $success): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)'
        );
        $stmt->execute([substr($username, 0, 64), substr($ip, 0, 45), $success ? 1 : 0]);
    }
}

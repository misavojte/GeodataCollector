<?php
declare(strict_types=1);

/**
 * One-off account setup (administrator or collector).
 *
 * SECURITY:
 *   - Works only when config.php contains 'setup' => ['setup_enabled' => true]
 *     (the default is false = disabled).
 *   - In addition, a non-empty token is REQUIRED: 'setup_token' => 'secret' and calling ?token=secret.
 *   - AFTER CREATING the accounts, set setup_enabled => false (and ideally delete this file)!
 */

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

if (Config::get('setup.setup_enabled') !== true) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>Account creation is disabled. Set setup.setup_enabled = true in config.php.</p>';
    exit;
}

// The token is REQUIRED and must be long enough (min. 16 characters) – it is the only barrier
// while setup_enabled = true. A short/guessable token would amount to open registration.
$requiredToken = (string) Config::get('setup.setup_token', '');
$providedToken = (string) ($_GET['token'] ?? '');
if (strlen($requiredToken) < 16) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>Account creation is protected by a token at least 16 characters long. '
        . 'Set a longer setup.setup_token in config.php (e.g. 32 random characters).</p>';
    exit;
}
// Constant-time comparison (against timing attacks).
if (!hash_equals($requiredToken, $providedToken)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>Account creation requires a valid token. Call this page with ?token=…</p>';
    exit;
}

Page::securityHeaders();
header('Content-Type: text/html; charset=utf-8');

$message = null;
$error = null;
$csrf = Csrf::token();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::requireValid($_POST['csrf'] ?? null);

        $username = trim((string) ($_POST['username'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password2'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
            throw new RuntimeException('Username: 3–64 characters (letters, digits, _ . -).');
        }
        if ($displayName === '') {
            throw new RuntimeException('Enter a display name.');
        }
        if (mb_strlen($password) < 8) {
            throw new RuntimeException('The password must be at least 8 characters long.');
        }
        if ($password !== $password2) {
            throw new RuntimeException('The passwords do not match.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid e-mail.');
        }
        $role = (string) ($_POST['role'] ?? 'collector');
        if (!in_array($role, ['admin', 'collector'], true)) {
            throw new RuntimeException('Invalid role.');
        }

        $exists = Database::pdo()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $exists->execute([$username]);
        if ($exists->fetch()) {
            throw new RuntimeException('A user with this username already exists.');
        }

        $stmt = Database::pdo()->prepare(
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

        $roleLabel = $role === 'admin' ? 'administrator' : 'collector';
        $message = 'Account “' . Page::e($username) . '” (' . $roleLabel . ') has been created. '
            . 'When you are done, set setup.setup_enabled = false in config.php (or delete this file).';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account</title>
    <link rel="stylesheet" href="../fonts/poppins.css">
    <link rel="stylesheet" href="../assets/login.css">
</head>
<body class="login-page">
    <main class="login-card">
        <h1 class="login-title">Create account</h1>

        <?php if ($message !== null): ?>
            <div class="alert" style="background:#e7f6ec;color:#15803d;border:1px solid #b7e4c7;">
                <?= $message /* contains the already-escaped username */ ?>
            </div>
            <p><a href="../login.php">Go to sign-in →</a></p>
        <?php else: ?>
            <?php if ($error !== null): ?>
                <div class="alert alert-error"><?= Page::e($error) ?></div>
            <?php endif; ?>
            <form method="post" class="login-form">
                <input type="hidden" name="csrf" value="<?= Page::e($csrf) ?>">

                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autocapitalize="none">

                <label for="display_name">Display name</label>
                <input type="text" id="display_name" name="display_name" required>

                <label for="email">E-mail (optional)</label>
                <input type="email" id="email" name="email">

                <label for="role">Account role</label>
                <select id="role" name="role">
                    <option value="collector">Collector</option>
                    <option value="admin">Administrator</option>
                </select>

                <label for="password">Password (min. 8 characters)</label>
                <input type="password" id="password" name="password" required>

                <label for="password2">Repeat password</label>
                <input type="password" id="password2" name="password2" required>

                <button type="submit" class="btn btn-primary btn-block">Create account</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>

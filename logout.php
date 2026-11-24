<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
require APP_ROOT . '/app/bootstrap.php';

// Sign out only via POST + a valid CSRF token (CSRF protection).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::validate($_POST['csrf'] ?? null)) {
    $user = Auth::currentUser();
    if ($user) {
        AuditLog::record((int) $user['id'], 'auth.logout', 'user', (int) $user['id'], null, $_SERVER['REMOTE_ADDR'] ?? null);
    }
    Auth::logout();
}

header('Location: login.php');
exit;

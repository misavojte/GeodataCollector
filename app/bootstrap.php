<?php
declare(strict_types=1);

/**
 * Common application bootstrap: configuration, autoloader, session.
 * Included at the start of every entry point (index.php, login.php, api.php …).
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// The Config class is needed before the environment is set up, so load it manually.
require APP_ROOT . '/app/Config.php';

// Simple autoloader for classes in app/ and app/controllers/.
spl_autoload_register(static function (string $class): void {
    foreach (['app', 'app/controllers'] as $dir) {
        $file = APP_ROOT . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

// Load configuration (config/config.php is created by copying config.example.php).
// GEODATA_CONFIG may point to another file (e.g. a separate demo/test instance or a container).
try {
    Config::load((string) (getenv('GEODATA_CONFIG') ?: APP_ROOT . '/config/config.php'));
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Configuration error: " . $e->getMessage();
    exit;
}

date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Prague'));
mb_internal_encoding('UTF-8');

$debug = (bool) Config::get('app.debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

// Secure session cookie settings and session start.
if (session_status() !== PHP_SESSION_ACTIVE) {
    // WARNING: session.use_strict_mode must NOT be set here. On shared hosting (PHP 8.0, files handler)
    // strict mode rejected even session IDs the server itself had just issued – every request
    // got a new empty session and login went round in circles (verified by diagnostics
    // 2026-07-12: the session file existed after the write, yet ID validation didn't accept it).
    // Same lesson as earlier with the __Host- prefix: verify ANY change to session behavior
    // in production with a one-off test first, and only then roll it out to users.
    ini_set('session.use_only_cookies', '1');
    session_name((string) Config::get('security.session_name', 'zvk_sess'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (bool) Config::get('security.cookie_secure', true),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

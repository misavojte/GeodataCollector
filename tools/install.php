<?php
declare(strict_types=1);

/**
 * Command-line installer.
 *
 *   php tools/install.php                      # create tables, default data and the first admin
 *   php tools/install.php --demo               # … and load the demo dataset (db/demo.sql)
 *   php tools/install.php --admin=jane --password=secret123 --name="Jane Doe"   # non-interactive
 *
 * Reads the database connection from config/config.php (or the file named in GEODATA_CONFIG).
 * Safe to run repeatedly: tables are created only if missing, default data uses INSERT IGNORE,
 * and an admin is created only when the given username does not exist yet.
 *
 * On hosting without shell access, import db/schema.sql + db/seed.sql in phpMyAdmin and
 * create the first account with tools/create_admin.php instead (see DEPLOY.md).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/Config.php';
spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/app/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$opts = getopt('', ['demo', 'admin:', 'password:', 'name:', 'email:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php tools/install.php [--demo] [--admin=USER --password=PASS [--name=NAME] [--email=EMAIL]]\n";
    exit(0);
}

function out(string $msg): void { fwrite(STDOUT, $msg . PHP_EOL); }
function fail(string $msg): void { fwrite(STDERR, 'Error: ' . $msg . PHP_EOL); exit(1); }

function ask(string $label, bool $secret = false): string
{
    fwrite(STDOUT, $label . ': ');
    if ($secret && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty -echo');
        $value = fgets(STDIN);
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    } else {
        $value = fgets(STDIN);
    }
    return trim((string) $value);
}

/** Splits an SQL file into statements (no stored procedures in our files, so ";" at line end is enough). */
function sqlStatements(string $file): array
{
    $sql = (string) file_get_contents($file);
    $lines = array_filter(
        preg_split('/\R/', $sql) ?: [],
        static fn(string $l): bool => !preg_match('/^\s*--/', $l)
    );
    $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
}

function runFile(PDO $pdo, string $file): int
{
    $n = 0;
    foreach (sqlStatements($file) as $stmt) {
        $pdo->exec($stmt);
        $n++;
    }
    return $n;
}

// --- configuration + connection ------------------------------------------------
try {
    Config::load((string) (getenv('GEODATA_CONFIG') ?: APP_ROOT . '/config/config.php'));
} catch (Throwable $e) {
    fail($e->getMessage() . "\nCreate it first:  cp config/config.example.php config/config.php  (then fill in the db section)");
}

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    fail('Cannot connect to the database: ' . $e->getMessage());
}
out('Connected to database "' . Config::get('db.name') . '".');

// --- schema + default data --------------------------------------------------------
try {
    out('Creating tables (db/schema.sql) …  ' . runFile($pdo, APP_ROOT . '/db/schema.sql') . ' statements');
    out('Loading default data (db/seed.sql) …  ' . runFile($pdo, APP_ROOT . '/db/seed.sql') . ' statements');
} catch (Throwable $e) {
    fail('SQL import failed: ' . $e->getMessage());
}

// --- first administrator ------------------------------------------------------------
$username = $opts['admin'] ?? null;
$password = $opts['password'] ?? null;
$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

if ($username === null && $adminCount > 0) {
    out("An administrator already exists – skipping account creation (use --admin/--password to add one).");
} else {
    if ($username === null) {
        out('');
        out('Create the first administrator account:');
        $username = ask('  Username');
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', (string) $username)) {
        fail('Username: 3–64 characters (letters, digits, _ . -).');
    }
    $exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetch()) {
        out("User \"$username\" already exists – not changed.");
    } else {
        if ($password === null) {
            $password = ask('  Password (min. 8 characters)', true);
        }
        if (mb_strlen((string) $password) < 8) {
            fail('The password must have at least 8 characters.');
        }
        $email = $opts['email'] ?? null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('Invalid e-mail address.');
        }
        $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, display_name, role, is_active)
             VALUES (?, ?, ?, ?, 'admin', 1)"
        )->execute([
            $username,
            $email,
            password_hash((string) $password, PASSWORD_DEFAULT),
            (string) ($opts['name'] ?? $username),
        ]);
        out("Administrator \"$username\" created.");
    }
}

// --- optional demo data -------------------------------------------------------------
if (isset($opts['demo'])) {
    try {
        out('Loading demo data (db/demo.sql) …  ' . runFile($pdo, APP_ROOT . '/db/demo.sql') . ' statements');
    } catch (Throwable $e) {
        fail('Demo import failed: ' . $e->getMessage());
    }
}

out('');
out('Done. Start a local server with:  php -S 127.0.0.1:8088 router.php   (after building the frontend)');

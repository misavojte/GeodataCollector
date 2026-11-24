<?php
declare(strict_types=1);

/**
 * Log of database errors and self-healing to logs/db.log (in addition to the regular error_log).
 * On shared hosting the server error_log is not easy to inspect – this file can be
 * downloaded via FTP, so you can see WHAT exactly happens in production and HOW OFTEN
 * (lost isolation, dropped connections, exhausted retries). Not web-accessible
 * (logs/.htaccess); writing must never break the request.
 */
final class DbLog
{
    private const MAX_BYTES = 524288; // ~0.5 MB: then the log is simply started afresh

    public static function write(string $message): void
    {
        error_log('DB: ' . $message);
        try {
            $dir = APP_ROOT . '/logs';
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0775, true)) {
                    return;
                }
                @file_put_contents(
                    $dir . '/.htaccess',
                    "# Logs must not be accessible from the web.\n"
                    . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
                );
            }
            $file = $dir . '/db.log';
            if (is_file($file) && (int) @filesize($file) > self::MAX_BYTES) {
                @unlink($file); // primitive rotation – history beyond 0.5 MB isn't worth keeping
            }
            @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // logging is just a helper – never propagate any error
        }
    }
}

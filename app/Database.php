<?php
declare(strict_types=1);

/**
 * Single database connection instance (PDO).
 * All queries in the application use prepared statements.
 *
 * Shared hosting specifics (verified in production):
 *  - the server gives sessions READ UNCOMMITTED by default, and the proxy can swap the backend
 *    connection even in the middle of a request – so SET SESSION cannot be reliably kept.
 *    The application INTENTIONALLY DOES NOT DEPEND on isolation: the spatial index that threw
 *    1207 under READ UNCOMMITTED was dropped (db/migrate_drop_spatial_index.sql);
 *    the pin below is only a best-effort guard against dirty reads.
 *  - the proxy sometimes hands out a connection that is "dead on arrival" or kills it between
 *    queries – so reads are retried once or twice on connection loss (retryReadOnConnectionLoss).
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** How many extra RETRIES a read query gets on connection loss. */
    private const RETRIES = 2;

    /** "Connection gone / unavailable" errors: too many connections, killed, gone away, lost. */
    private const CONNECTION_ERRNOS = [1040, 1203, 1927, 2002, 2006, 2013];

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
            try {
                // Best-effort disabling of dirty reads. If the pin is lost (the proxy swaps
                // the connection), NOTHING breaks – no application query requires isolation.
                self::$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            } catch (Throwable $e) {
                // SET is the first statement on a new connection – failure means the proxy handed out
                // a dead connection. One retry with a fresh one; a second failure propagates.
                DbLog::write('new connection dead right after connecting, trying a fresh one: ' . $e->getMessage());
                self::$pdo = self::connect();
                self::$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
        }
        return self::$pdo;
    }

    private static function connect(): PDO
    {
        $host    = (string) Config::get('db.host', 'localhost');
        $port    = (int) Config::get('db.port', 3306);
        $name    = (string) Config::get('db.name', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        return new PDO(
            $dsn,
            (string) Config::get('db.user', ''),
            (string) Config::get('db.pass', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );
    }

    /** MySQL/MariaDB error number from the exception (errorInfo; for connection errors it is often only in the message). */
    private static function errno(Throwable $e): int
    {
        if (!$e instanceof PDOException) {
            return 0;
        }
        $errno = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($errno === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
            $errno = (int) $m[1];
        }
        return $errno;
    }

    /**
     * Runs a READ-ONLY $fn and on connection loss retries it on a fresh connection
     * (max RETRIES retries, with a short backoff). Only for queries without side
     * effects – writes are NOT retried: if the outage hit the COMMIT, there is no way to tell
     * whether it went through, and a retry would apply the change twice.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function retryReadOnConnectionLoss(callable $fn)
    {
        // Nothing is retried inside a running transaction – a reconnect would discard its state.
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            return $fn();
        }

        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (Throwable $e) {
                if ($attempt >= self::RETRIES || !in_array(self::errno($e), self::CONNECTION_ERRNOS, true)) {
                    throw $e;
                }
                $attempt++;
                DbLog::write('connection lost, retrying read (attempt ' . $attempt . '/' . self::RETRIES . '): ' . $e->getMessage());
                usleep(200000 * $attempt); // 0.2/0.4 s – give a transient spike time to subside
                self::$pdo = null; // the next pdo() (inside $fn) opens a fresh connection
            }
        }
    }

    /**
     * Runs $fn in a transaction: commit on success, rollback (and re-throw) on exception.
     * The inTransaction() guard also handles the case where the transaction is no longer running (after commit),
     * and ties in with the nested-safe FeatureRevisionRepository::record()/count().
     * No retries (see retryReadOnConnectionLoss) – a failed write is reported by the API
     * and the user/auto-save retries it deliberately.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollbackFail) {
                    // dead connection – the server has cleaned up the transaction itself
                }
            }
            throw $e;
        }
    }
}

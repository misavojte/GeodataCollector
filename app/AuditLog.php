<?php
declare(strict_types=1);

/**
 * Lightweight audit log. Serves as a foundation for a future admin overview.
 * Never store sensitive data in it (passwords etc.).
 */
final class AuditLog
{
    public static function record(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $payload = null,
        ?string $ip = null
    ): void {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_log (user_id, action, entity_type, entity_id, payload, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                substr($action, 0, 64),
                $entityType !== null ? substr($entityType, 0, 64) : null,
                $entityId,
                $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                $ip !== null ? substr($ip, 0, 45) : null,
            ]);
            // Self-maintenance: occasionally (0.5 %) prune old audit rows (18-month retention) so the
            // table doesn't grow forever (no cron dependency). Covered by idx_audit_time. NEVER inside
            // a transaction (audit is also logged during feature writes) – a large range DELETE would
            // hold locks and prolong another mutation; so it only runs on the autocommit path (typically auth.login).
            if (!Database::pdo()->inTransaction() && random_int(1, 200) === 1) {
                Database::pdo()->exec('DELETE FROM audit_log WHERE created_at < (NOW() - INTERVAL 18 MONTH)');
            }
        } catch (Throwable $e) {
            // Auditing must never crash the app – just log the error.
            error_log('AuditLog failed: ' . $e->getMessage());
        }
    }
}

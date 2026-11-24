<?php
declare(strict_types=1);

/**
 * Feature version history (feature_revisions table).
 *  - Every change stores a snapshot of the state (geometry + attributes).
 *  - Serves as an audit trail and a source for restore (revert / undelete).
 *  - History is never deleted (full trail).
 */
final class FeatureRevisionRepository
{
    /**
     * Revision merge window: if edits to the same feature by the same user follow each other within
     * this many seconds, the last revision is overwritten instead of creating a new one → continuous editing =
     * one revision (clean history without micro-revisions). A pause longer than the window creates a new revision.
     */
    private const MERGE_WINDOW_SECONDS = 5;

    /**
     * Records a snapshot of the feature state. For 'update' it skips no-ops (identical to the last revision) and
     * merges quick successive edits (see MERGE_WINDOW_SECONDS).
     */
    /** @return int id of the written/merged revision (0 = no-op / best-effort skipped). */
    public function record(int $featureId, string $action, string $geometryType, string $wkt, string $attributesJson, ?int $userId): int
    {
        // Outside a transaction it's best-effort (data is written via autocommit) – we only log. INSIDE
        // a transaction (atomic write from FeatureController) we propagate the failure so the feature
        // change is rolled back too (see catch below) – the revision is the safety net for restore and must not be missing.
        try {
            if ($action === 'update') {
                $stmt = Database::pdo()->prepare(
                    'SELECT id, action, changed_by, ST_AsText(geom) AS wkt, attributes,
                            TIMESTAMPDIFF(SECOND, changed_at, NOW()) AS age
                     FROM feature_revisions WHERE feature_id = ? ORDER BY id DESC LIMIT 1'
                );
                $stmt->execute([$featureId]);
                $last = $stmt->fetch();
                if ($last !== false) {
                    if ((string) $last['wkt'] === $wkt && (string) $last['attributes'] === $attributesJson) {
                        return 0; // nothing actually changed
                    }
                    // Merging: the last revision is an 'update' by the same user within the window → overwrite it
                    // (incl. bumping the timestamp, so continuous editing stays a single revision with the current state).
                    if ($last['action'] === 'update'
                        && $userId !== null
                        && (int) $last['changed_by'] === $userId
                        && $last['age'] !== null
                        && (int) $last['age'] <= self::MERGE_WINDOW_SECONDS) {
                        Database::pdo()->prepare(
                            'UPDATE feature_revisions
                                SET geom = ST_GeomFromText(?, 0), attributes = ?, geometry_type = ?, changed_at = NOW()
                              WHERE id = ?'
                        )->execute([$wkt, $attributesJson, $geometryType, (int) $last['id']]);
                        return (int) $last['id'];
                    }
                }
            }

            $pdo = Database::pdo();
            $pdo->prepare(
                'INSERT INTO feature_revisions (feature_id, action, geometry_type, geom, attributes, changed_by)
                 VALUES (?, ?, ?, ST_GeomFromText(?, 0), ?, ?)'
            )->execute([$featureId, $action, $geometryType, $wkt, $attributesJson, $userId]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            // Inside a transaction (atomic write from FeatureController) a revision failure must also roll back
            // the feature change – so we propagate the error. Outside a transaction it stays best-effort
            // (the change is already written via autocommit), so we only log it.
            if (Database::pdo()->inTransaction()) {
                throw $e;
            }
            error_log('feature_revisions: recording revision failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Number of revisions of the given feature (0 on error – must not break the write). */
    public function count(int $featureId): int
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM feature_revisions WHERE feature_id = ?');
            $stmt->execute([$featureId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            if (Database::pdo()->inTransaction()) {
                throw $e;
            }
            error_log('feature_revisions: count failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Revisions of a single feature (newest first) – incl. geometry (WKT) for diffing. */
    public function findByFeature(int $featureId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.id, r.action, r.geometry_type, ST_AsText(r.geom) AS wkt, r.attributes, r.changed_at, u.display_name AS changed_by_name
             FROM feature_revisions r
             LEFT JOIN users u ON u.id = r.changed_by
             WHERE r.feature_id = ?
             ORDER BY r.id DESC'
        );
        $stmt->execute([$featureId]);
        return $stmt->fetchAll();
    }

    /** A single revision including geometry (WKT) – for restore. */
    public function find(int $revId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, feature_id, action, geometry_type, ST_AsText(geom) AS wkt, attributes
             FROM feature_revisions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$revId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Recent changes across features (for the admin overview). */
    public function recent(int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $sql = "SELECT r.id, r.feature_id, r.action, r.geometry_type, r.changed_at,
                       JSON_UNQUOTE(JSON_EXTRACT(r.attributes, '$.nazev')) AS nazev,
                       u.display_name AS changed_by_name,
                       (f.deleted_at IS NOT NULL) AS feature_deleted
                FROM feature_revisions r
                LEFT JOIN users u ON u.id = r.changed_by
                LEFT JOIN features f ON f.id = r.feature_id
                ORDER BY r.id DESC
                LIMIT $limit OFFSET $offset";
        $rows = Database::pdo()->query($sql)->fetchAll();
        return array_map(static function (array $r): array {
            return [
                'id'              => (int) $r['id'],
                'feature_id'      => (int) $r['feature_id'],
                'action'          => $r['action'],
                'geometry_type'   => $r['geometry_type'],
                'nazev'           => $r['nazev'],
                'changed_at'      => $r['changed_at'],
                'changed_by_name' => $r['changed_by_name'],
                'feature_deleted' => (int) $r['feature_deleted'] === 1,
            ];
        }, $rows);
    }
}

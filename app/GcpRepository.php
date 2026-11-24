<?php
declare(strict_types=1);

/**
 * Access to reference points (GCP – Ground Control Points) in the gcp_pairs table.
 * Point pairs (old map vs. reference base map) for georeferencing a historical map.
 */
final class GcpRepository
{
    /**
     * Returns all active GCP pairs (optionally filtered to a specific author).
     */
    public function all(?int $createdBy = null): array
    {
        $sql = 'SELECT g.id, g.label, g.description,
                       ST_AsGeoJSON(g.geom_map, 6) AS map_geojson,
                       ST_AsGeoJSON(g.geom_ref, 6) AS ref_geojson,
                       g.created_by, g.updated_by, g.created_at, g.updated_at,
                       cu.display_name AS created_by_name,
                       uu.display_name AS updated_by_name
                FROM gcp_pairs g
                LEFT JOIN users cu ON cu.id = g.created_by
                LEFT JOIN users uu ON uu.id = g.updated_by
                WHERE g.deleted_at IS NULL';
        $params = [];

        if ($createdBy !== null) {
            $sql .= ' AND g.created_by = ?';
            $params[] = $createdBy;
        }

        $sql .= ' ORDER BY g.id ASC';

        return Database::retryReadOnConnectionLoss(static function () use ($sql, $params): array {
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        });
    }

    /**
     * Detail of a single GCP pair by ID.
     */
    public function find(int $id): ?array
    {
        $sql = 'SELECT g.id, g.label, g.description,
                       ST_AsGeoJSON(g.geom_map, 6) AS map_geojson,
                       ST_AsGeoJSON(g.geom_ref, 6) AS ref_geojson,
                       ST_AsText(g.geom_map) AS map_wkt,
                       ST_AsText(g.geom_ref) AS ref_wkt,
                       g.created_by, g.updated_by, g.created_at, g.updated_at,
                       cu.display_name AS created_by_name,
                       uu.display_name AS updated_by_name
                FROM gcp_pairs g
                LEFT JOIN users cu ON cu.id = g.created_by
                LEFT JOIN users uu ON uu.id = g.updated_by
                WHERE g.id = ? AND g.deleted_at IS NULL LIMIT 1';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Suggests the next sequence number (e.g. for prefilling "GCP 1", "GCP 2"...).
     */
    public function nextSuggestedNumber(): int
    {
        return Database::retryReadOnConnectionLoss(static function (): int {
            $pdo = Database::pdo();
            $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM gcp_pairs')->fetchColumn();
            return $maxId + 1;
        });
    }

    /**
     * Author and deletion info for a record (for permission checks).
     */
    public function ownerInfo(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT created_by, (deleted_at IS NOT NULL) AS deleted FROM gcp_pairs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false
            ? ['created_by' => (int) $row['created_by'], 'deleted' => (int) $row['deleted'] === 1]
            : null;
    }

    /**
     * Inserts a new GCP pair.
     */
    public function create(string $label, ?string $description, string $wktMap, string $wktRef, int $userId): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO gcp_pairs (label, description, geom_map, geom_ref, created_by)
             VALUES (?, ?, ST_GeomFromText(?, 0), ST_GeomFromText(?, 0), ?)'
        );
        $stmt->execute([
            $label,
            $description !== null && trim($description) !== '' ? trim($description) : null,
            $wktMap,
            $wktRef,
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Updates an existing GCP pair.
     */
    public function update(
        int $id,
        ?string $label,
        ?string $description,
        ?string $wktMap,
        ?string $wktRef,
        int $userId
    ): void {
        $sets = ['updated_by = ?'];
        $params = [$userId];

        if ($label !== null) {
            $sets[] = 'label = ?';
            $params[] = $label;
        }
        if ($description !== null) {
            $sets[] = 'description = ?';
            $params[] = trim($description) !== '' ? trim($description) : null;
        }
        if ($wktMap !== null) {
            $sets[] = 'geom_map = ST_GeomFromText(?, 0)';
            $params[] = $wktMap;
        }
        if ($wktRef !== null) {
            $sets[] = 'geom_ref = ST_GeomFromText(?, 0)';
            $params[] = $wktRef;
        }

        $params[] = $id;
        $sql = 'UPDATE gcp_pairs SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL';
        Database::pdo()->prepare($sql)->execute($params);
    }

    /**
     * Soft-deletes a GCP pair.
     */
    public function softDelete(int $id, int $userId): void
    {
        Database::pdo()
            ->prepare('UPDATE gcp_pairs SET deleted_at = CURRENT_TIMESTAMP, updated_by = ?
                       WHERE id = ? AND deleted_at IS NULL')
            ->execute([$userId, $id]);
    }
}

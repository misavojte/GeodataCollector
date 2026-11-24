<?php
declare(strict_types=1);

/**
 * Access to collected geometries (features table).
 *  - Geometry to/from the DB via WKT (ST_GeomFromText / ST_AsText), SRID 0.
 *  - Map viewport selection via MBRIntersects ROW BY ROW – without a spatial index,
 *    INTENTIONALLY: an R-tree requires predicate locks, which under READ UNCOMMITTED (the hosting
 *    default, the pin doesn't hold) throw 1207. See db/migrate_drop_spatial_index.sql.
 *  - Deletion is a "soft delete" (deleted_at).
 */
final class FeatureRepository
{
    // Result cap: prevents a single request (esp. a zoomed-out map or a manual
    // ?limit) from building tens of MB in PHP memory and sending them over the wire. Real density is low,
    // so typical viewports return on the order of tens to hundreds of rows; the cap is just a safeguard.
    private const DEFAULT_LIMIT = 3000;
    private const MAX_LIMIT = 8000;
    private const MAX_EXPORT = 10000;

    /**
     * Returns features within the viewport (optional) and matching the filters.
     * @param array{0:float,1:float,2:float,3:float}|null $bbox [minLon,minLat,maxLon,maxLat]
     * @param array{type?:string, created_by?:int, limit?:int} $filters
     */
    public function findInBbox(?array $bbox, array $filters = []): array
    {
        // The DB returns geometry directly as GeoJSON (ST_AsGeoJSON – no WKT parsing in PHP),
        // coordinates rounded to 6 decimals (~11 cm; smaller body + compresses better).
        // MBRIntersects is evaluated row by row (no spatial index – see the class
        // header), so the query does NOT DEPEND on session isolation and works under any level.
        $sql = 'SELECT f.id, f.geometry_type, ST_AsGeoJSON(f.geom, 6) AS geojson, f.attributes, f.status,
                       f.created_by, f.updated_by, f.created_at, f.updated_at,
                       cu.display_name AS created_by_name,
                       uu.display_name AS updated_by_name
                FROM features f
                LEFT JOIN users cu ON cu.id = f.created_by
                LEFT JOIN users uu ON uu.id = f.updated_by
                WHERE f.deleted_at IS NULL';
        $params = [];

        if ($bbox !== null) {
            $sql .= ' AND MBRIntersects(ST_GeomFromText(?, 0), f.geom)';
            $params[] = $this->envelopeWkt($bbox);
        }
        if (!empty($filters['type'])) {
            $sql .= ' AND f.geometry_type = ?';
            $params[] = (string) $filters['type'];
        }
        if (!empty($filters['created_by'])) {
            $sql .= ' AND f.created_by = ?';
            $params[] = (int) $filters['created_by'];
        }

        $limit = (int) ($filters['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        // ORDER BY only on the unbounded (admin) path. For a bbox query, id-desc ordering would force
        // a filesort over the spatial range (confirmed by EXPLAIN) – the map draws features regardless
        // of order and the Features list sorts itself, so the cap here acts purely as a safeguard.
        if ($bbox === null) {
            $sql .= ' ORDER BY f.id DESC';
        }
        $sql .= ' LIMIT ' . $limit;

        return Database::retryReadOnConnectionLoss(static function () use ($sql, $params): array {
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        });
    }

    /**
     * Monotonic "data version" for the frontend cache (cheap probe before using the cache).
     * = highest id in feature_revisions: grows on EVERY feature change (create/update/delete/
     * restore write a revision in the SAME transaction as the mutation – unlike the former audit_log
     * it isn't best-effort and login traffic doesn't bump it). MAX(id) goes through the PK → O(1); id is
     * auto-increment (monotonic), so the version never decreases. (Two quick edits by the same user within
     * 5 s are merged into one revision → the second doesn't bump the version; staleness is bounded by the cache TTL.)
     */
    public function dataVersion(): int
    {
        // The first /api/features query and the frequent /api/features/version probe → they hit a broken
        // connection before findInBbox does, hence the same self-healing.
        return Database::retryReadOnConnectionLoss(static fn(): int =>
            (int) Database::pdo()->query('SELECT COALESCE(MAX(id), 0) FROM feature_revisions')->fetchColumn());
    }

    /** Author + whether the feature is deleted (also for soft-deleted; for authorizing restore/undo). */
    public function ownerInfo(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT created_by, (deleted_at IS NOT NULL) AS deleted FROM features WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? ['created_by' => (int) $row['created_by'], 'deleted' => (int) $row['deleted'] === 1] : null;
    }

    public function find(int $id): ?array
    {
        // Detail: GeoJSON (6 decimals) for the response and WKT for the revision snapshot, plus full attributes.
        $stmt = Database::pdo()->prepare(
            'SELECT f.id, f.geometry_type, ST_AsGeoJSON(f.geom, 6) AS geojson, ST_AsText(f.geom) AS wkt,
                    f.attributes, f.status,
                    f.created_by, f.updated_by, f.created_at, f.updated_at,
                    cu.display_name AS created_by_name,
                    uu.display_name AS updated_by_name
             FROM features f
             LEFT JOIN users cu ON cu.id = f.created_by
             LEFT JOIN users uu ON uu.id = f.updated_by
             WHERE f.id = ? AND f.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Features by a list of ids for export (geometry as WKT for conversion to GeoJSON).
     * @param int[] $ids
     */
    public function findByIdsForExport(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        if ($ids === []) {
            return [];
        }
        $ids = array_slice($ids, 0, self::MAX_EXPORT);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT f.id, f.geometry_type, ST_AsText(f.geom) AS wkt, f.attributes,
                       f.created_at, cu.display_name AS created_by_name
                FROM features f
                LEFT JOIN users cu ON cu.id = f.created_by
                WHERE f.deleted_at IS NULL AND f.id IN (' . $placeholders . ')
                ORDER BY f.id DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    public function create(string $geometryType, string $wkt, array $attributes, int $userId): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO features (geometry_type, geom, attributes, created_by)
             VALUES (?, ST_GeomFromText(?, 0), ?, ?)'
        );
        $stmt->execute([
            $geometryType,
            $wkt,
            // (object) ensures empty attributes are stored as {} (not []).
            json_encode((object) $attributes, JSON_UNESCAPED_UNICODE),
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Updates geometry and/or attributes.
     * @param string|null $wkt        new geometry (null = unchanged)
     * @param array|null  $attributes new attributes (null = unchanged)
     */
    public function update(int $id, ?string $wkt, ?array $attributes, int $userId): void
    {
        $sets = ['updated_by = ?'];
        $params = [$userId];

        if ($wkt !== null) {
            $sets[] = 'geom = ST_GeomFromText(?, 0)';
            $params[] = $wkt;
        }
        if ($attributes !== null) {
            $sets[] = 'attributes = ?';
            $params[] = json_encode((object) $attributes, JSON_UNESCAPED_UNICODE);
        }

        $params[] = $id;
        $sql = 'UPDATE features SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL';
        Database::pdo()->prepare($sql)->execute($params);
    }

    /**
     * Restores a feature to the state of a revision: sets geometry + attributes and clears
     * any soft delete (undelete). For reverting changes / restoring deleted features.
     */
    public function restoreTo(int $id, string $wkt, string $attributesJson, int $userId): void
    {
        Database::pdo()->prepare(
            'UPDATE features SET geom = ST_GeomFromText(?, 0), attributes = ?, deleted_at = NULL, updated_by = ?
             WHERE id = ?'
        )->execute([$wkt, $attributesJson, $userId, $id]);
    }

    public function softDelete(int $id, int $userId): void
    {
        Database::pdo()
            ->prepare('UPDATE features SET deleted_at = CURRENT_TIMESTAMP, updated_by = ?
                       WHERE id = ? AND deleted_at IS NULL')
            ->execute([$userId, $id]);
    }

    /** @param array{0:float,1:float,2:float,3:float} $bbox */
    private function envelopeWkt(array $bbox): string
    {
        $minLon = sprintf('%.8f', (float) $bbox[0]);
        $minLat = sprintf('%.8f', (float) $bbox[1]);
        $maxLon = sprintf('%.8f', (float) $bbox[2]);
        $maxLat = sprintf('%.8f', (float) $bbox[3]);
        return "POLYGON(($minLon $minLat, $maxLon $minLat, $maxLon $maxLat, $minLon $maxLat, $minLon $minLat))";
    }
}

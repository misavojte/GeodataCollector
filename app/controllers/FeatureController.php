<?php
declare(strict_types=1);

/**
 * REST endpoints for collected geometries.
 *   GET    /api/features            list (optionally ?bbox=&type=&mine=1&limit=)
 *   GET    /api/features/{id}       single record
 *   POST   /api/features            create
 *   PUT    /api/features/{id}       update (geometry and/or attributes)
 *   DELETE /api/features/{id}       delete (soft delete)
 */
final class FeatureController
{
    private GeometryService $geometry;
    private FieldService $fields;
    private FeatureRepository $repo;
    private FeatureRevisionRepository $revisions;

    /** Maps a GeoJSON type to the internal type. */
    private const TYPE_FROM_GEOJSON = [
        'Point'      => 'point',
        'LineString' => 'line',
        'Polygon'    => 'polygon',
    ];

    public function __construct()
    {
        $this->geometry = new GeometryService();
        $this->fields = new FieldService();
        $this->repo = new FeatureRepository();
        $this->revisions = new FeatureRevisionRepository();
    }

    public function index(Request $req): void
    {
        $user = Auth::requireAuth();

        // Read the data version BEFORE the query → conservative guarantee: if a later probe matches
        // this version, nothing has changed in between, so the loaded data is guaranteed to be fresh.
        $version = $this->repo->dataVersion();

        $bbox = $this->parseBbox($req->query('bbox'));

        $filters = [];
        $type = (string) $req->query('type', '');
        if (in_array($type, ['point', 'line', 'polygon'], true)) {
            $filters['type'] = $type;
        }
        if ((string) $req->query('mine', '') === '1') {
            $filters['created_by'] = (int) $user['id'];
        }
        if ($req->query('limit') !== null) {
            $filters['limit'] = (int) $req->query('limit');
        }

        $rows = $this->repo->findInBbox($bbox, $filters);
        if (connection_aborted()) {
            exit;
        }
        $features = array_map(fn($r) => $this->shapeFeature($r, $user), $rows);

        Response::json([
            'type'     => 'FeatureCollection',
            'features' => $features,
            'version'  => $version,
        ]);
    }

    /**
     * Cheap "fingerprint" of the data state – the frontend checks it before reusing a cached extent and
     * reloads only on change (others' edits thus show up without a full query on every map move).
     */
    public function version(Request $req): void
    {
        Auth::requireAuth();
        Response::json(['version' => $this->repo->dataVersion()]);
    }

    public function show(Request $req, array $params): void
    {
        $user = Auth::requireAuth();
        $row = $this->repo->find((int) $params['id']);
        if ($row === null) {
            throw new HttpException('Record not found.', 404, 'feature_not_found');
        }
        Response::json($this->shapeFeature($row, $user));
    }

    public function store(Request $req): void
    {
        $user = Auth::requireAuth();
        $body = $req->body();

        $geometry = $body['geometry'] ?? null;
        if (!is_array($geometry)) {
            throw new HttpException('Geometry is missing.', 422, 'geometry_missing');
        }

        $geometryType = $this->resolveType($body, $geometry);
        $wkt = $this->geometry->geoJsonToWkt($geometry, $geometryType);

        $attributesInput = isset($body['attributes']) && is_array($body['attributes']) ? $body['attributes'] : [];
        $attributes = $this->fields->validateAttributes($attributesInput, $geometryType, null, (int) $user['id']);
        if ($geometryType === 'polygon') {
            $this->assertValidPolygon($wkt);
        }

        // Write the record and its first revision atomically (the revision is the safety net for restores).
        $row = Database::transaction(function () use ($geometryType, $wkt, $attributes, $user, $req) {
            $id = $this->repo->create($geometryType, $wkt, $attributes, (int) $user['id']);
            (new AttachmentService())->linkToFeature($this->fields->attachmentIds($attributes), $id);
            $created = $this->repo->find($id);
            if ($created === null) {
                throw new HttpException('Failed to load the record.', 500, 'feature_load_failed');
            }
            $this->revisions->record($id, 'create', $created['geometry_type'], (string) $created['wkt'], (string) $created['attributes'], (int) $user['id']);
            AuditLog::record((int) $user['id'], 'feature.create', 'feature', $id, ['type' => $geometryType], $req->clientIp());
            return $created;
        });
        Response::json($this->shapeFeature($row, $user), 201);
    }

    public function update(Request $req, array $params): void
    {
        $user = Auth::requireAuth();
        $id = (int) $params['id'];

        $row = $this->repo->find($id);
        if ($row === null) {
            throw new HttpException('Record not found.', 404, 'feature_not_found');
        }
        $this->assertCanEdit($row, $user);

        $body = $req->body();
        $geometryType = $row['geometry_type'];

        $wkt = null;
        if (isset($body['geometry']) && is_array($body['geometry'])) {
            $wkt = $this->geometry->geoJsonToWkt($body['geometry'], $geometryType);
        }

        $attributes = null;
        if (isset($body['attributes']) && is_array($body['attributes'])) {
            $attributes = $this->fields->validateAttributes($body['attributes'], $geometryType, $id, (int) $user['id']);
        }

        if ($wkt === null && $attributes === null) {
            throw new HttpException('Nothing to save (no geometry or attributes).', 422, 'nothing_to_save');
        }
        if ($wkt !== null && $geometryType === 'polygon') {
            $this->assertValidPolygon($wkt);
        }

        // Update the record and write a revision atomically (see store()).
        $updated = Database::transaction(function () use ($id, $wkt, $attributes, $row, $user, $req) {
            $this->repo->update($id, $wkt, $attributes, (int) $user['id']);
            if ($attributes !== null) {
                (new AttachmentService())->linkToFeature($this->fields->attachmentIds($attributes), $id);
            }

            $fresh = $this->repo->find($id);
            if ($fresh === null) {
                throw new HttpException('Failed to load the record.', 500, 'feature_load_failed');
            }
            // A record created outside the app (seed/import) has no "create" revision – on the first
            // edit, capture the original (pre-edit) state from $row first so it can be reverted to the original.
            if ($this->revisions->count($id) === 0) {
                $this->revisions->record($id, 'create', $row['geometry_type'], (string) $row['wkt'], (string) $row['attributes'], (int) $user['id']);
            }
            $this->revisions->record($id, 'update', $fresh['geometry_type'], (string) $fresh['wkt'], (string) $fresh['attributes'], (int) $user['id']);
            AuditLog::record((int) $user['id'], 'feature.update', 'feature', $id, null, $req->clientIp());
            return $fresh;
        });
        Response::json($this->shapeFeature($updated, $user));
    }

    public function destroy(Request $req, array $params): void
    {
        $user = Auth::requireAuth();
        $id = (int) $params['id'];

        $row = $this->repo->find($id);
        if ($row === null) {
            throw new HttpException('Record not found.', 404, 'feature_not_found');
        }
        $this->assertCanEdit($row, $user);

        // Snapshot of the state BEFORE deletion + soft delete, atomically: without the snapshot the deleted
        // record could not be restored (the revision is the only backup), so both must happen together. The client
        // uses the returned id of the 'delete' revision for "Undo" right after deletion.
        $revId = Database::transaction(function () use ($id, $row, $user, $req) {
            $rid = $this->revisions->record($id, 'delete', $row['geometry_type'], (string) $row['wkt'], (string) $row['attributes'], (int) $user['id']);
            $this->repo->softDelete($id, (int) $user['id']);
            AuditLog::record((int) $user['id'], 'feature.delete', 'feature', $id, null, $req->clientIp());
            return $rid;
        });

        Response::json(['deleted' => true, 'id' => $id, 'revision_id' => $revId]);
    }

    // ----------------------------------------------- history / revisions ---

    /** Revisions of a single record incl. geometry and attributes (for diffing). Admin only. */
    public function revisions(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $rows = $this->revisions->findByFeature((int) $params['id']);
        $out = array_map(function (array $r): array {
            $attrs = json_decode((string) $r['attributes'], true);
            if (!is_array($attrs)) {
                $attrs = [];
            }
            return [
                'id'              => (int) $r['id'],
                'action'          => $r['action'],
                'geometry_type'   => $r['geometry_type'],
                'geometry'        => $this->geometry->wktToGeoJson((string) $r['wkt']),
                'attributes'      => (object) $attrs,
                'changed_at'      => $r['changed_at'],
                'changed_by_name' => $r['changed_by_name'],
            ];
        }, $rows);
        Response::json(['revisions' => $out]);
    }

    /** Recent changes across records – audit overview (admin only). */
    public function recentChanges(Request $req): void
    {
        Auth::requireAdmin();
        $limit = $req->query('limit') !== null ? (int) $req->query('limit') : 50;
        $offset = $req->query('offset') !== null ? (int) $req->query('offset') : 0;
        Response::json(['changes' => $this->revisions->recent($limit, $offset)]);
    }

    /** Restores a record to the state of a revision (reverting a change or restoring a deleted record). Admin only, except for the author's undo of a deletion (see below). */
    public function restore(Request $req, array $params): void
    {
        $user = Auth::requireAuth();
        $id = (int) $params['id'];
        $revId = (int) ($req->body()['revision_id'] ?? 0);

        $rev = $this->revisions->find($revId);
        if ($rev === null || (int) $rev['feature_id'] !== $id) {
            throw new HttpException('Revision not found.', 404, 'revision_not_found');
        }
        // Authorization: an admin may restore anything to any revision. The author may restore ONLY their own
        // record and only while it is deleted (i.e. "Undo" after deletion – not an arbitrary revert).
        $info = $this->repo->ownerInfo($id);
        if ($info === null) {
            throw new HttpException('Record not found.', 404, 'feature_not_found');
        }
        if (($user['role'] ?? '') !== 'admin') {
            if ($info['created_by'] !== (int) $user['id']) {
                throw new HttpException('Only the record author or an administrator can restore this record.', 403, 'restore_author_only');
            }
            if (!$info['deleted']) {
                throw new HttpException('Reverting to an older version is done by an administrator.', 403, 'restore_admin_only');
            }
        }

        // Restore + 'restore' revision atomically (see store()).
        $row = Database::transaction(function () use ($id, $rev, $revId, $user, $req) {
            $this->repo->restoreTo($id, (string) $rev['wkt'], (string) $rev['attributes'], (int) $user['id']);
            $fresh = $this->repo->find($id);
            if ($fresh === null) {
                throw new HttpException('Failed to load the record.', 500, 'feature_load_failed');
            }
            $this->revisions->record($id, 'restore', $fresh['geometry_type'], (string) $fresh['wkt'], (string) $fresh['attributes'], (int) $user['id']);
            AuditLog::record((int) $user['id'], 'feature.restore', 'feature', $id, ['revision' => $revId], $req->clientIp());
            return $fresh;
        });
        Response::json($this->shapeFeature($row, $user));
    }

    public function export(Request $req): void
    {
        $user = Auth::requireAuth();
        $body = $req->body();
        $ids = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : [];
        if ($ids === []) {
            throw new HttpException('No records selected for export.', 422, 'export_no_ids');
        }

        $rows = $this->repo->findByIdsForExport($ids);
        if ($rows === []) {
            throw new HttpException('No records to export.', 404, 'export_empty');
        }

        // GeoJSON FeatureCollection (RFC 7946 = WGS-84 lon/lat). Attributes go into
        // properties; extra metadata is prefixed with _ (field keys start with a letter,
        // so they cannot collide).
        // Attachments get a download link in the export (works once signed in to the app).
        $attachmentKeys = [];
        foreach ($this->fields->getActiveDefinitions() as $def) {
            if (in_array($def['data_type'], FieldService::ATTACHMENT_TYPES, true)) {
                $attachmentKeys[] = $def['field_key'];
            }
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $apiBase = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/') . '/api/attachments/';

        $features = [];
        foreach ($rows as $r) {
            $attrs = json_decode((string) $r['attributes'], true);
            if (!is_array($attrs)) {
                $attrs = [];
            }
            foreach ($attachmentKeys as $k) {
                if (is_array($attrs[$k] ?? null)) {
                    $attrs[$k] = array_map(static fn($a) => is_array($a) && isset($a['id'])
                        ? $a + ['url' => $apiBase . (int) $a['id']] : $a, $attrs[$k]);
                }
            }
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $this->geometry->wktToGeoJson((string) $r['wkt']),
                'properties' => array_merge($attrs, [
                    '_id'        => (int) $r['id'],
                    '_type'      => $r['geometry_type'],
                    '_author'    => $r['created_by_name'],
                    '_created'   => $r['created_at'],
                ]),
            ];
        }

        AuditLog::record((int) $user['id'], 'feature.export', 'feature', null, ['count' => count($features)], $req->clientIp());

        $filename = 'records-' . date('Y-m-d') . '.geojson';
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/geo+json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
        }
        echo json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --------------------------------------------------------- helpers -------

    private function resolveType(array $body, array $geometry): string
    {
        $explicit = (string) ($body['geometry_type'] ?? '');
        if (in_array($explicit, ['point', 'line', 'polygon'], true)) {
            return $explicit;
        }
        $gjType = is_string($geometry['type'] ?? null) ? $geometry['type'] : '';
        $type = self::TYPE_FROM_GEOJSON[$gjType] ?? null;
        if ($type === null) {
            throw new HttpException('Unsupported geometry type.', 422, 'geom_type_unsupported');
        }
        return $type;
    }

    /**
     * Rejects a polygon with crossing edges (bow-tie). MariaDB does NOT have ST_IsValid (unlike
     * MySQL), but ST_IsSimple reliably detects self-intersection (verified on MariaDB 11.4: bow-tie=0,
     * valid quadrilateral=1). SRID 0 (Cartesian), same as when writing.
     */
    private function assertValidPolygon(string $wkt): void
    {
        $stmt = Database::pdo()->prepare('SELECT ST_IsSimple(ST_GeomFromText(?, 0))');
        $stmt->execute([$wkt]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new HttpException('The area shape is invalid (e.g. crossing edges). Adjust the outline.', 422, 'polygon_self_intersect');
        }
    }

    private function assertCanEdit(array $row, array $user): void
    {
        $isOwner = (int) $row['created_by'] === (int) $user['id'];
        $isAdmin = ($user['role'] ?? '') === 'admin';
        if (!$isOwner && !$isAdmin) {
            throw new HttpException('Only the record author or an administrator can edit this record.', 403, 'feature_edit_forbidden');
        }
    }

    /** Full shape (detail / after write): geometry + all attributes + metadata. */
    private function shapeFeature(array $row, array $user): array
    {
        $attributes = json_decode((string) $row['attributes'], true);
        if (!is_array($attributes)) {
            $attributes = [];
        }

        return [
            'type'     => 'Feature',
            'id'       => (int) $row['id'],
            'geometry' => $this->geomFromRow($row),
            'properties' => [
                // (object) → empty attributes are returned as {} in the JSON response (not []).
                'attributes' => (object) $attributes,
                'meta' => $this->shapeMeta($row, $user),
            ],
        ];
    }

    /** Record metadata (used by shapeFeature). */
    private function shapeMeta(array $row, array $user): array
    {
        $canEdit = (int) $row['created_by'] === (int) $user['id'] || ($user['role'] ?? '') === 'admin';
        return [
            'id'              => (int) $row['id'],
            'geometry_type'   => $row['geometry_type'],
            'status'          => $row['status'],
            'created_by'      => (int) $row['created_by'],
            'created_by_name' => $row['created_by_name'] ?? null,
            'updated_by'      => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updated_by_name' => $row['updated_by_name'] ?? null,
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
            'can_edit'        => $canEdit,
        ];
    }

    /** Geometry from a row: GeoJSON column from the DB (ST_AsGeoJSON) → array. */
    private function geomFromRow(array $row): array
    {
        $g = json_decode((string) ($row['geojson'] ?? ''), true);
        if (!is_array($g)) {
            throw new HttpException('Failed to load the geometry.', 500, 'geometry_load_failed');
        }
        return $g;
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function parseBbox($raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $parts = explode(',', $raw);
        if (count($parts) !== 4) {
            throw new HttpException('The bbox parameter must have 4 values: minLon,minLat,maxLon,maxLat.', 422, 'bbox_format');
        }
        foreach ($parts as $p) {
            if (!is_numeric($p)) {
                throw new HttpException('The bbox parameter contains an invalid number.', 422, 'bbox_number');
            }
        }
        return [(float) $parts[0], (float) $parts[1], (float) $parts[2], (float) $parts[3]];
    }
}

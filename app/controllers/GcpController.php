<?php
declare(strict_types=1);

/**
 * REST endpoints for reference points (GCP pairs).
 *   GET    /api/gcp                 list (optionally ?mine=1)
 *   GET    /api/gcp/next-label      suggested label for a new record ("GCP N")
 *   GET    /api/gcp/{id}            single GCP pair
 *   POST   /api/gcp                 create a new GCP pair
 *   PUT    /api/gcp/{id}            update a GCP pair
 *   DELETE /api/gcp/{id}            delete (soft delete)
 */
final class GcpController
{
    private GeometryService $geometry;
    private GcpRepository $repo;

    public function __construct()
    {
        $this->geometry = new GeometryService();
        $this->repo = new GcpRepository();
    }

    public function index(Request $req): void
    {
        $user = Auth::requireAuth();
        $mineOnly = (string) $req->query('mine', '') === '1';
        $createdBy = $mineOnly ? (int) $user['id'] : null;

        $rows = $this->repo->all($createdBy);
        $pairs = array_map(fn(array $r) => $this->shapePair($r, $user), $rows);

        Response::json(['pairs' => $pairs]);
    }

    public function nextLabel(Request $req): void
    {
        Auth::requireAuth();
        $num = $this->repo->nextSuggestedNumber();
        Response::json(['label' => 'GCP ' . $num]);
    }

    public function show(Request $req, array $params): void
    {
        $user = Auth::requireAuth();
        $id = (int) $params['id'];
        $row = $this->repo->find($id);
        if ($row === null) {
            throw new HttpException('GCP pair not found.', 404, 'gcp_not_found');
        }
        Response::json($this->shapePair($row, $user));
    }

    public function store(Request $req): void
    {
        $user = Auth::requireAuth();
        $body = $req->body();

        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            throw new HttpException('Label is required.', 422, 'gcp_label_required', [
                'fields' => [['field' => 'label', 'message' => 'Label is required.']]
            ]);
        }
        if (mb_strlen($label) > 255) {
            throw new HttpException('Label is too long (max 255 chars).', 422, 'gcp_label_too_long');
        }

        $mapGeom = $body['map_geometry'] ?? null;
        if (!is_array($mapGeom)) {
            throw new HttpException('Map point geometry is missing.', 422, 'gcp_map_geom_missing');
        }

        $refGeom = $body['ref_geometry'] ?? null;
        if (!is_array($refGeom)) {
            throw new HttpException('Reference point geometry is missing.', 422, 'gcp_ref_geom_missing');
        }

        $wktMap = $this->geometry->geoJsonToWkt($mapGeom, 'point');
        $wktRef = $this->geometry->geoJsonToWkt($refGeom, 'point');

        $description = isset($body['description']) && is_string($body['description'])
            ? $body['description']
            : null;

        $id = $this->repo->create($label, $description, $wktMap, $wktRef, (int) $user['id']);
        $created = $this->repo->find($id);
        if ($created === null) {
            throw new HttpException('Failed to load created GCP pair.', 500, 'gcp_load_failed');
        }

        AuditLog::record((int) $user['id'], 'gcp.create', 'gcp', $id, ['label' => $label], $req->clientIp());

        Response::json($this->shapePair($created, $user), 201);
    }

    public function update(Request $req, array $params): void
    {
        $user = Auth::requireAuth();
        $id = (int) $params['id'];

        $row = $this->repo->find($id);
        if ($row === null) {
            throw new HttpException('GCP pair not found.', 404, 'gcp_not_found');
        }
        $this->assertCanEdit($row, $user);

        $body = $req->body();

        $label = null;
        if (isset($body['label'])) {
            $label = trim((string) $body['label']);
            if ($label === '') {
                throw new HttpException('Label cannot be empty.', 422, 'gcp_label_required');
            }
            if (mb_strlen($label) > 255) {
                throw new HttpException('Label is too long (max 255 chars).', 422, 'gcp_label_too_long');
            }
        }

        $description = null;
        if (array_key_exists('description', $body)) {
            $description = is_string($body['description']) ? $body['description'] : null;
        }

        $wktMap = null;
        if (isset($body['map_geometry']) && is_array($body['map_geometry'])) {
            $wktMap = $this->geometry->geoJsonToWkt($body['map_geometry'], 'point');
        }

        $wktRef = null;
        if (isset($body['ref_geometry']) && is_array($body['ref_geometry'])) {
            $wktRef = $this->geometry->geoJsonToWkt($body['ref_geometry'], 'point');
        }

        $this->repo->update($id, $label, $description, $wktMap, $wktRef, (int) $user['id']);

        $fresh = $this->repo->find($id);
        if ($fresh === null) {
            throw new HttpException('Failed to load updated GCP pair.', 500, 'gcp_load_failed');
        }

        AuditLog::record((int) $user['id'], 'gcp.update', 'gcp', $id, null, $req->clientIp());

        Response::json($this->shapePair($fresh, $user));
    }

    public function destroy(Request $req, array $params): void
    {
        $user = Auth::requireAuth();
        $id = (int) $params['id'];

        $row = $this->repo->find($id);
        if ($row === null) {
            throw new HttpException('GCP pair not found.', 404, 'gcp_not_found');
        }
        $this->assertCanEdit($row, $user);

        $this->repo->softDelete($id, (int) $user['id']);
        AuditLog::record((int) $user['id'], 'gcp.delete', 'gcp', $id, null, $req->clientIp());

        Response::json(['deleted' => true, 'id' => $id]);
    }

    // --------------------------------------------------------- helpers -------

    private function assertCanEdit(array $row, array $user): void
    {
        $isOwner = (int) $row['created_by'] === (int) $user['id'];
        $isAdmin = ($user['role'] ?? '') === 'admin';
        if (!$isOwner && !$isAdmin) {
            throw new HttpException('Only the record author or an administrator can edit this GCP pair.', 403, 'gcp_edit_forbidden');
        }
    }

    private function shapePair(array $row, array $user): array
    {
        $mapGeom = json_decode((string) ($row['map_geojson'] ?? ''), true);
        $refGeom = json_decode((string) ($row['ref_geojson'] ?? ''), true);

        $mapCoords = is_array($mapGeom) && isset($mapGeom['coordinates'][0], $mapGeom['coordinates'][1])
            ? ['lon' => (float) $mapGeom['coordinates'][0], 'lat' => (float) $mapGeom['coordinates'][1]]
            : null;
        $refCoords = is_array($refGeom) && isset($refGeom['coordinates'][0], $refGeom['coordinates'][1])
            ? ['lon' => (float) $refGeom['coordinates'][0], 'lat' => (float) $refGeom['coordinates'][1]]
            : null;

        $canEdit = (int) $row['created_by'] === (int) $user['id'] || ($user['role'] ?? '') === 'admin';

        return [
            'id'              => (int) $row['id'],
            'label'           => (string) $row['label'],
            'description'     => $row['description'] !== null ? (string) $row['description'] : null,
            'map_point'       => $mapCoords,
            'ref_point'       => $refCoords,
            'created_by'      => (int) $row['created_by'],
            'created_by_name' => $row['created_by_name'] ?? null,
            'updated_by'      => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updated_by_name' => $row['updated_by_name'] ?? null,
            'created_at'      => (string) $row['created_at'],
            'updated_at'      => (string) $row['updated_at'],
            'can_edit'        => $canEdit,
        ];
    }
}

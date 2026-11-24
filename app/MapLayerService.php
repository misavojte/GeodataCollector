<?php
declare(strict_types=1);

/**
 * Data-driven map layers (map_layers table), managed by the administrator.
 *  - A single ordered list: the admin changes the order (sort_order) by drag and drop; the FIRST
 *    in order is the default base layer. No "kind" – any layer can be used in the panel
 *    both as a base layer and as an overlay (the client handles opacity for overlays).
 *  - Only PUBLIC services are stored (no credentials).
 *
 * Modeled on FieldService (dynamic field registry) – same validation and shaping style.
 */
final class MapLayerService
{
    private const TYPES     = ['wms', 'xyz', 'wmts'];
    private const FORMATS   = ['image/png', 'image/jpeg'];
    private const BUILTIN_CRS = ['EPSG:3857', 'EPSG:4326']; // built into OpenLayers, no proj4 needed

    /** Transliteration of Czech diacritics for building the key (layer_key). */
    private const ASCII = [
        'á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r',
        'š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z',
        'Á'=>'a','Č'=>'c','Ď'=>'d','É'=>'e','Ě'=>'e','Í'=>'i','Ň'=>'n','Ó'=>'o','Ř'=>'r',
        'Š'=>'s','Ť'=>'t','Ú'=>'u','Ů'=>'u','Ý'=>'y','Ž'=>'z',
    ];

    /** Layers for the frontend (same shape as sanitizeLayer in LayerController), sorted. */
    public function getApiLayers(): array
    {
        $rows = Database::pdo()
            ->query('SELECT * FROM map_layers ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
        $keyById = [];
        foreach ($rows as $r) {
            $keyById[(int) $r['id']] = (string) $r['layer_key'];
        }
        return array_map(function (array $row) use ($keyById) {
            return $this->shapeForApi($row, $keyById);
        }, $rows);
    }

    /** All layers for admin management (in the same order as on the map). */
    public function getAllForAdmin(): array
    {
        $rows = Database::pdo()
            ->query('SELECT * FROM map_layers ORDER BY sort_order ASC, id ASC')
            ->fetchAll();
        return array_map([$this, 'shapeForAdmin'], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM map_layers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $this->shapeForAdmin($row) : null;
    }

    public function create(array $input, int $userId): int
    {
        $data = $this->normalize($input);
        $key = $this->uniqueKey($data['title']);

        // Read MAX(sort_order) + INSERT atomically (like reorder), so concurrency can't produce a duplicate.
        return Database::transaction(function (PDO $pdo) use ($key, $data, $userId) {
            $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM map_layers')->fetchColumn();
            $stmt = $pdo->prepare(
                'INSERT INTO map_layers
                    (layer_key, title, type, url, capabilities_url, wms_layers, subdomains, format,
                     version, projection, crs_config, wmts_config, attribution, min_zoom, max_zoom, parent_id, sort_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $key, $data['title'], $data['type'], $data['url'], $data['capabilities_url'], $data['wms_layers'],
                $data['subdomains'], $data['format'], $data['version'], $data['projection'],
                $data['crs_config'], $data['wmts_config'], $data['attribution'],
                $data['min_zoom'], $data['max_zoom'], $data['parent_id'], $next, $userId,
            ]);
            return (int) $pdo->lastInsertId();
        });
    }

    public function update(int $id, array $input): void
    {
        $data = $this->normalize($input, $id);
        $stmt = Database::pdo()->prepare(
            'UPDATE map_layers SET
                title = ?, type = ?, url = ?, capabilities_url = ?, wms_layers = ?, subdomains = ?,
                format = ?, version = ?, projection = ?, crs_config = ?, wmts_config = ?, attribution = ?,
                min_zoom = ?, max_zoom = ?, parent_id = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['title'], $data['type'], $data['url'], $data['capabilities_url'], $data['wms_layers'],
            $data['subdomains'], $data['format'], $data['version'], $data['projection'],
            $data['crs_config'], $data['wmts_config'], $data['attribution'],
            $data['min_zoom'], $data['max_zoom'], $data['parent_id'], $id,
        ]);
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM map_layers WHERE id = ?')->execute([$id]);
    }

    /**
     * Sets the order according to the given list of ids (index = new sort_order).
     * Unknown ids are ignored; layers not in the list keep their sort_order.
     * @param int[] $ids
     */
    public function reorder(array $ids): void
    {
        Database::transaction(function (PDO $pdo) use ($ids) {
            $stmt = $pdo->prepare('UPDATE map_layers SET sort_order = ? WHERE id = ?');
            $i = 1;
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                $stmt->execute([$i, $id]);
                $i++;
            }
        });
    }

    // ----------------------------------------------------- validation --------

    /** Validates and normalizes the input; returns column values. @throws HttpException 422 */
    private function normalize(array $in, ?int $currentId = null): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            $this->fail('layer_title_required', 'The layer name is required.');
        }
        if (mb_strlen($title) > 190) {
            $this->fail('layer_title_too_long', 'The layer name is too long (max 190 characters).');
        }

        $type = (string) ($in['type'] ?? 'wms');
        if (!in_array($type, self::TYPES, true)) {
            $this->fail('layer_type_invalid', 'Invalid layer type.');
        }

        $url = trim((string) ($in['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $this->fail('layer_url_scheme', 'The URL must start with http:// or https://.');
        }
        if (mb_strlen($url) > 1024) {
            $this->fail('layer_url_too_long', 'The URL is too long.');
        }

        // strip_tags: the attribution is rendered as HTML in the map (OpenLayers), so tags must not get through.
        $attribution = trim(strip_tags((string) ($in['attribution'] ?? '')));
        $attribution = $attribution !== '' ? mb_substr($attribution, 0, 255) : null;

        $minZoom = isset($in['min_zoom']) && $in['min_zoom'] !== '' && $in['min_zoom'] !== null
            ? (int) $in['min_zoom'] : null;
        $maxZoom = isset($in['max_zoom']) && $in['max_zoom'] !== '' && $in['max_zoom'] !== null
            ? (int) $in['max_zoom'] : null;

        if ($minZoom !== null && ($minZoom < 0 || $minZoom > 28)) {
            $this->fail('layer_min_zoom_invalid', 'Min zoom must be between 0 and 28.');
        }
        if ($maxZoom !== null && ($maxZoom < 0 || $maxZoom > 28)) {
            $this->fail('layer_max_zoom_invalid', 'Max zoom must be between 0 and 28.');
        }
        if ($minZoom !== null && $maxZoom !== null && $minZoom > $maxZoom) {
            $this->fail('layer_zoom_range_invalid', 'Min zoom cannot be greater than max zoom.');
        }

        $parentId = isset($in['parent_id']) && $in['parent_id'] !== '' && $in['parent_id'] !== null
            ? (int) $in['parent_id'] : null;

        if ($parentId !== null) {
            if ($currentId !== null && $parentId === $currentId) {
                $this->fail('layer_parent_self', 'A layer cannot be its own parent.');
            }
            $stmt = Database::pdo()->prepare('SELECT id FROM map_layers WHERE id = ? LIMIT 1');
            $stmt->execute([$parentId]);
            if ($stmt->fetch() === false) {
                $this->fail('layer_parent_not_found', 'Selected parent layer does not exist.');
            }
        }

        $out = [
            'title'            => $title,
            'type'             => $type,
            'url'              => $url,
            'capabilities_url' => null,
            'wms_layers'       => null,
            'subdomains'       => null,
            'format'           => null,
            'version'          => null,
            'projection'       => null,
            'crs_config'       => null,
            'wmts_config'      => null,
            'attribution'      => $attribution,
            'min_zoom'         => $minZoom,
            'max_zoom'         => $maxZoom,
            'parent_id'        => $parentId,
        ];

        if ($type === 'wms') {
            return $this->normalizeWms($in, $out);
        }
        if ($type === 'wmts') {
            return $this->normalizeWmts($in, $out);
        }
        return $this->normalizeXyz($in, $out);
    }

    private function normalizeXyz(array $in, array $out): array
    {
        $url = $out['url'];
        if (!preg_match('/\{x\}/', $url) || !preg_match('/\{y\}/', $url) || !preg_match('/\{z\}/', $url)) {
            $this->fail('xyz_template_placeholders', 'The XYZ template must contain {x}, {y} and {z}.');
        }
        // Subdomains only make sense with the {s} placeholder; otherwise they would be silently dropped.
        $sub = trim((string) ($in['subdomains'] ?? ''));
        $hasS = strpos($url, '{s}') !== false;
        if ($hasS && $sub === '') {
            $this->fail('xyz_subdomains_missing', 'The template contains {s} - fill in the subdomains (e.g. "abc").');
        }
        $out['subdomains'] = ($hasS && $sub !== '') ? mb_substr($sub, 0, 16) : null;
        return $out;
    }

    private function normalizeWms(array $in, array $out): array
    {
        $wmsLayers = trim((string) ($in['wms_layers'] ?? ''));
        if ($wmsLayers === '') {
            $this->fail('wms_layer_required', 'A WMS layer must be selected (the LAYERS parameter).');
        }
        if (mb_strlen($wmsLayers) > 255) {
            $this->fail('wms_layers_too_long', 'The LAYERS parameter is too long.');
        }
        $out['wms_layers'] = $wmsLayers;

        $format = (string) ($in['format'] ?? 'image/png');
        $out['format']  = in_array($format, self::FORMATS, true) ? $format : 'image/png';
        $out['version'] = '1.3.0'; // the only supported version

        // CRS (optional, default EPSG:3857). A non-built-in CRS requires a proj4 def.
        [$out['projection'], $out['crs_config']] = $this->normalizeCrs($in, $out['projection']);

        // Strict validation via GetCapabilities (when the "Load layers" wizard was used).
        $caps = $this->normalizeCapsUrl($in);
        if ($caps !== null) {
            $out['capabilities_url'] = $caps;
            (new CapabilitiesService())->validateChoice($caps, 'WMS', ['layer' => $wmsLayers]);
        }
        return $out;
    }

    private function normalizeWmts(array $in, array $out): array
    {
        $caps = $this->normalizeCapsUrl($in);
        if ($caps === null) {
            $this->fail('wmts_needs_capabilities', 'WMTS layers must be loaded via GetCapabilities (the capabilities address is missing).');
        }
        $out['capabilities_url'] = $caps;

        $layer  = trim((string) ($in['layer'] ?? ''));
        $matrix = trim((string) ($in['matrix_set'] ?? ''));
        $style  = trim((string) ($in['style'] ?? '')) ?: 'default';
        $format = (string) ($in['format'] ?? 'image/png');
        if ($layer === '' || $matrix === '') {
            $this->fail('wmts_layer_and_set_required', 'Select a WMTS layer and a tile matrix set (TileMatrixSet).');
        }
        if (mb_strlen($layer) > 255) {
            $this->fail('wmts_layer_too_long', 'The layer identifier is too long.');
        }
        if (!preg_match('#^image/[\w.+-]{1,30}$#', $format)) {
            $this->fail('tile_format_invalid', 'Invalid tile format.');
        }
        $enc = strtoupper(trim((string) ($in['request_encoding'] ?? '')));
        if ($enc !== 'KVP' && $enc !== 'REST') {
            $this->fail('request_encoding_invalid', 'Invalid request encoding (KVP/REST).');
        }

        // CRS – required for WMTS (even 3857 as projection); a non-built-in one needs a proj4 def.
        [$projection, $crsConfig] = $this->normalizeCrs($in, 'EPSG:3857');
        $out['projection'] = $projection;
        $out['crs_config'] = $crsConfig;

        $tileGrid = $this->normalizeTileGrid($in['tile_grid'] ?? null);

        // Strict validation of the selection against fresh capabilities (cannot be saved "from memory").
        (new CapabilitiesService())->validateChoice(
            $caps, 'WMTS',
            ['layer' => $layer, 'matrix_set' => $matrix, 'format' => $format, 'style' => $style]
        );

        $out['wms_layers']  = $layer; // for display in the admin
        $out['format']      = $format;
        $out['wmts_config'] = json_encode([
            'requestEncoding' => $enc,
            'layer'           => $layer,
            'matrixSet'       => $matrix,
            'style'           => $style,
            'format'          => $format,
            'tileGrid'        => $tileGrid,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $out;
    }

    /** Returns [projection|null, crs_config_json|null]. */
    private function normalizeCrs(array $in, ?string $fallback): array
    {
        $code = trim((string) ($in['projection'] ?? ''));
        if ($code === '') {
            $code = $fallback;
        }
        if ($code === null) {
            return [null, null];
        }
        if (!preg_match('/^EPSG:\d{3,7}$/', $code)) {
            $this->fail('crs_code_invalid', 'Invalid CRS code (e.g. EPSG:5514 expected).');
        }
        if (in_array($code, self::BUILTIN_CRS, true)) {
            return [$code, null]; // 3857/4326 are built into OL, no proj4 def needed
        }
        // Non-built-in CRS: a proj4 definition is required (from the wizard via /map-layers/crs).
        $crs = is_array($in['crs'] ?? null) ? $in['crs'] : [];
        $def = trim((string) ($crs['proj4def'] ?? ''));
        if ($def === '' || stripos($def, '+proj=') === false) {
            $this->fail('crs_missing_proj4', 'CRS ' . $code . ' is missing a valid proj4 definition (load the layers via GetCapabilities).', ['code' => $code]);
        }
        if (($crs['code'] ?? $code) !== $code) {
            $this->fail('crs_code_mismatch', 'The CRS code does not match the proj4 definition.');
        }
        $config = [
            'code'            => $code,
            'proj4def'        => mb_substr($def, 0, 1024),
            'units'           => preg_match('/^[a-z-]{1,12}$/', (string) ($crs['units'] ?? '')) ? $crs['units'] : 'm',
            'axisOrientation' => preg_match('/^[a-z]{2,3}$/', (string) ($crs['axisOrientation'] ?? '')) ? $crs['axisOrientation'] : 'enu',
        ];
        foreach (['extent', 'worldExtent'] as $k) {
            if (isset($crs[$k]) && is_array($crs[$k]) && count($crs[$k]) === 4
                && count(array_filter($crs[$k], 'is_numeric')) === 4) {
                $config[$k] = array_map('floatval', $crs[$k]);
            }
        }
        return [$code, json_encode($config, JSON_UNESCAPED_SLASHES)];
    }

    private function normalizeCapsUrl(array $in): ?string
    {
        $caps = trim((string) ($in['capabilities_url'] ?? ''));
        if ($caps === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $caps) || mb_strlen($caps) > 1024) {
            $this->fail('caps_url_invalid', 'Invalid GetCapabilities address.');
        }
        return $caps;
    }

    /** Validates the structure of the grid computed by the client (origin/resolutions/matrixIds/tileSize). */
    private function normalizeTileGrid($g): array
    {
        if (!is_array($g)) {
            $this->fail('tile_grid_missing', 'The tile grid (tile_grid) is missing.');
        }
        $matrixIds = $g['matrixIds'] ?? null;
        $resolutions = $g['resolutions'] ?? null;
        if (!is_array($matrixIds) || $matrixIds === [] || count($matrixIds) > 64) {
            $this->fail('tile_grid_matrix_ids', 'Invalid matrixIds.');
        }
        if (!is_array($resolutions) || count($resolutions) !== count($matrixIds)
            || count(array_filter($resolutions, fn($r) => is_numeric($r) && $r > 0)) !== count($resolutions)) {
            $this->fail('tile_grid_resolutions', 'Invalid resolutions (must match the number of levels and be positive).');
        }
        $matrixIds   = array_map(fn($m) => mb_substr((string) $m, 0, 64), $matrixIds);
        $resolutions = array_map('floatval', $resolutions);

        $isXy = fn($p) => is_array($p) && count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1]);
        $out = ['matrixIds' => $matrixIds, 'resolutions' => $resolutions];
        if ($isXy($g['origin'] ?? null)) {
            $out['origin'] = [(float) $g['origin'][0], (float) $g['origin'][1]];
        } elseif (is_array($g['origins'] ?? null) && count($g['origins']) === count($matrixIds)
                  && count(array_filter($g['origins'], $isXy)) === count($matrixIds)) {
            $out['origins'] = array_map(fn($p) => [(float) $p[0], (float) $p[1]], $g['origins']);
        } else {
            $this->fail('tile_grid_origin', 'Invalid tile grid origin.');
        }

        $ts = $g['tileSize'] ?? 256;
        if (is_numeric($ts) && (int) $ts > 0 && (int) $ts <= 4096) {
            $out['tileSize'] = (int) $ts;
        } elseif (is_array($ts) && count($ts) === 2 && (int) $ts[0] > 0 && (int) $ts[1] > 0) {
            $out['tileSize'] = [(int) $ts[0], (int) $ts[1]];
        } else {
            $out['tileSize'] = 256;
        }
        return $out;
    }

    // ------------------------------------------------------- helpers ---------

    /**
     * Creates a unique layer_key from the title slug (with a numeric suffix if needed).
     * The key is generated only on creation; on update it stays unchanged (stable reference).
     */
    private function uniqueKey(string $title): string
    {
        $base = strtr($title, self::ASCII);
        $base = strtolower($base);
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
        $base = trim($base, '_');
        if ($base === '' || !preg_match('/^[a-z]/', $base)) {
            $base = rtrim('vrstva_' . $base, '_');
        }
        $base = substr($base, 0, 56);

        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM map_layers WHERE layer_key = ?');
        $candidate = $base;
        $n = 1;
        while (true) {
            $stmt->execute([$candidate]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
            $candidate = $base . '_' . (++$n);
        }
    }

    private function shapeForApi(array $row, array $keyById = []): array
    {
        $type = $row['type'];
        $out = [
            'id'    => $row['layer_key'],
            'title' => $row['title'],
            'type'  => $type,
            'proxy' => false,
        ];
        if ($type === 'wms') {
            $out['url']     = (string) $row['url'];
            $out['layers']  = (string) ($row['wms_layers'] ?? '');
            $out['format']  = (string) ($row['format'] ?: 'image/png');
            $out['version'] = (string) ($row['version'] ?: '1.3.0');
            $this->attachCrs($out, $row);
        } elseif ($type === 'wmts') {
            $cfg = $this->decodeJson($row['wmts_config']);
            // Complete WmtsLayerCfg for the client (OL builds the source offline, without network access).
            $out['wmts'] = [
                'url'             => (string) $row['url'],
                'requestEncoding' => (string) ($cfg['requestEncoding'] ?? 'KVP'),
                'layer'           => (string) ($cfg['layer'] ?? ''),
                'matrixSet'       => (string) ($cfg['matrixSet'] ?? ''),
                'style'           => (string) ($cfg['style'] ?? 'default'),
                'format'          => (string) ($cfg['format'] ?? ($row['format'] ?: 'image/png')),
                'projection'      => (string) ($row['projection'] ?: 'EPSG:3857'),
                'tileGrid'        => $cfg['tileGrid'] ?? null,
            ];
            $this->attachCrs($out, $row);
        } else { // xyz
            $out['url_template'] = (string) $row['url'];
            if (!empty($row['subdomains'])) {
                $out['subdomains'] = (string) $row['subdomains'];
            }
        }
        if (!empty($row['attribution'])) {
            $out['attribution'] = (string) $row['attribution'];
        }
        if (isset($row['min_zoom']) && $row['min_zoom'] !== null) {
            $out['min_zoom'] = (int) $row['min_zoom'];
        }
        if (isset($row['max_zoom']) && $row['max_zoom'] !== null) {
            $out['max_zoom'] = (int) $row['max_zoom'];
        }
        if (isset($row['parent_id']) && $row['parent_id'] !== null) {
            $parentId = (int) $row['parent_id'];
            $out['parent_id'] = $parentId;
            if (isset($keyById[$parentId])) {
                $out['parent_key'] = $keyById[$parentId];
            }
        }
        return $out;
    }

    /** Adds projection + crs (proj4 def) for reprojecting non-Mercator layers. */
    private function attachCrs(array &$out, array $row): void
    {
        if (!empty($row['projection'])) {
            $out['projection'] = (string) $row['projection'];
        }
        $crs = $this->decodeJson($row['crs_config']);
        if ($crs !== null && isset($crs['code'], $crs['proj4def'])) {
            $out['crs'] = $crs;
        }
    }

    private function decodeJson($val): ?array
    {
        if (!is_string($val) || $val === '') {
            return null;
        }
        $d = json_decode($val, true);
        return is_array($d) ? $d : null;
    }

    private function shapeForAdmin(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'layer_key'        => $row['layer_key'],
            'title'            => $row['title'],
            'type'             => $row['type'],
            'url'              => $row['url'],
            'capabilities_url' => $row['capabilities_url'],
            'wms_layers'       => $row['wms_layers'],
            'subdomains'       => $row['subdomains'],
            'format'           => $row['format'],
            'projection'       => $row['projection'],
            'crs_config'       => $this->decodeJson($row['crs_config']),
            'wmts_config'      => $this->decodeJson($row['wmts_config']),
            'attribution'      => $row['attribution'],
            'min_zoom'         => isset($row['min_zoom']) && $row['min_zoom'] !== null ? (int) $row['min_zoom'] : null,
            'max_zoom'         => isset($row['max_zoom']) && $row['max_zoom'] !== null ? (int) $row['max_zoom'] : null,
            'parent_id'        => isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
        ];
    }

    private function fail(string $code, string $message, array $params = []): void
    {
        throw new HttpException($message, 422, $code, [], $params);
    }
}

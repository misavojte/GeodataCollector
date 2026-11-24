<?php
declare(strict_types=1);

/**
 * Map layers.
 *   GET /api/layers       safe layer list (without credentials)
 *   GET /api/wms-proxy     proxy for private (authenticated) WMS
 *
 * The proxy ensures that credentials for a private WMS NEVER leave the server.
 * It forwards only to the URL configured in config/layers.php (no open proxy)
 * and takes the key parameters (LAYERS/FORMAT/VERSION) from the configuration, not from the client.
 */
final class LayerController
{
    /** GetMap parameters that may be taken from the client (tile rendering only). */
    private const ALLOWED_WMS_PARAMS = [
        'STYLES', 'TRANSPARENT', 'CRS', 'SRS', 'BBOX',
        'WIDTH', 'HEIGHT', 'BGCOLOR', 'EXCEPTIONS', 'TIME', 'DPI', 'MAP_RESOLUTION',
        'FORMAT_OPTIONS',
    ];

    /** Maximum tile dimension (px) and maximum response size (bytes). */
    private const MAX_TILE_PX = 4096;
    private const MAX_RESPONSE_BYTES = 16777216; // 16 MB

    private MapLayerService $layers;

    public function __construct()
    {
        $this->layers = new MapLayerService();
    }

    public function index(Request $req): void
    {
        Auth::requireAuth();

        // Primarily from the DB (managed by the admin). If the table is empty/missing (fresh
        // install without the migration), fall back to config/layers.php so the map
        // never ends up without a basemap.
        $layers = [];
        try {
            $layers = $this->layers->getApiLayers();
        } catch (Throwable $e) {
            error_log('map_layers unavailable, falling back to config: ' . $e->getMessage());
        }
        if (empty($layers)) {
            $layers = $this->apiLayersFromConfig();
        }
        Response::json(['layers' => $layers]);
    }

    // --- Layer management (admin only) ---

    public function manageIndex(Request $req): void
    {
        Auth::requireAdmin();
        Response::json(['layers' => $this->layers->getAllForAdmin()]);
    }

    public function store(Request $req): void
    {
        $user = Auth::requireAdmin();
        $id = $this->layers->create($req->body(), (int) $user['id']);
        AuditLog::record((int) $user['id'], 'layer.create', 'map_layer', $id, null, $req->clientIp());
        Response::json(['id' => $id], 201);
    }

    public function update(Request $req, array $params): void
    {
        $user = Auth::requireAdmin();
        $id = (int) $params['id'];
        if ($this->layers->find($id) === null) {
            throw new HttpException('Layer not found.', 404, 'layer_not_found');
        }
        $this->layers->update($id, $req->body());
        AuditLog::record((int) $user['id'], 'layer.update', 'map_layer', $id, null, $req->clientIp());
        Response::json(['updated' => true, 'id' => $id]);
    }

    public function destroy(Request $req, array $params): void
    {
        $user = Auth::requireAdmin();
        $id = (int) $params['id'];
        if ($this->layers->find($id) === null) {
            throw new HttpException('Layer not found.', 404, 'layer_not_found');
        }
        $this->layers->delete($id);
        AuditLog::record((int) $user['id'], 'layer.delete', 'map_layer', $id, null, $req->clientIp());
        Response::json(['deleted' => true, 'id' => $id]);
    }

    public function reorder(Request $req): void
    {
        $user = Auth::requireAdmin();
        $body = $req->body();
        $order = isset($body['order']) && is_array($body['order']) ? $body['order'] : [];
        $this->layers->reorder($order);
        AuditLog::record((int) $user['id'], 'layer.reorder', 'map_layer', null, null, $req->clientIp());
        Response::json(['reordered' => true]);
    }

    /**
     * Setup wizard: fetches GetCapabilities (SSRF-safe) and returns the list of available
     * layers/tile sets for the admin to choose from. Called only when creating a layer.
     */
    public function capabilities(Request $req): void
    {
        Auth::requireAdmin();
        $body = $req->body();
        $url     = trim((string) ($body['url'] ?? ''));
        $service = strtoupper(trim((string) ($body['service'] ?? '')));
        if ($url === '') {
            throw new HttpException('Enter the service address.', 422, 'caps_url_required');
        }
        $svc = new CapabilitiesService();
        $xml = $svc->fetchCapabilities($url, $service);
        Response::json(['capabilities' => $svc->parse($xml, $service)]);
    }

    /** Setup wizard: proj4 CRS definition (from epsg.io) for registering an unknown CRS in OpenLayers. */
    public function crs(Request $req): void
    {
        Auth::requireAdmin();
        $code = strtoupper(trim((string) $req->query('code', '')));
        $def  = (new CapabilitiesService())->fetchProj4Def($code);
        if ($def === null) {
            throw new HttpException('Failed to obtain a proj4 definition for ' . $code . '.', 422, 'crs_proj4_unavailable', [], ['code' => $code]);
        }
        Response::json(['code' => $code, 'proj4def' => $def]);
    }

    public function wmsProxy(Request $req): void
    {
        Auth::requireAuth();

        $layerId = (string) $req->query('layer', '');
        $layer = $this->findLayer($layerId);
        if ($layer === null || empty($layer['proxy'])) {
            throw new HttpException('Unknown or disallowed layer.', 404, 'layer_not_found');
        }

        // GetMap parameters from the client (allowed ones only).
        $params = [];
        foreach ($_GET as $key => $value) {
            $up = strtoupper((string) $key);
            if (in_array($up, self::ALLOWED_WMS_PARAMS, true) && is_scalar($value)) {
                $params[$up] = (string) $value;
            }
        }

        // Limit tile dimensions (protection against memory/bandwidth exhaustion).
        foreach (['WIDTH', 'HEIGHT'] as $dim) {
            if (isset($params[$dim])) {
                $n = (int) $params[$dim];
                if ($n < 1 || $n > self::MAX_TILE_PX) {
                    throw new HttpException('Invalid tile size.', 422, 'tile_size_invalid');
                }
                $params[$dim] = (string) $n;
            }
        }
        // BBOX must be exactly 4 numbers.
        if (isset($params['BBOX'])) {
            $parts = explode(',', $params['BBOX']);
            if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
                throw new HttpException('Invalid BBOX.', 422, 'bbox_invalid');
            }
        }

        // Key parameters are enforced from the configuration; REQUEST is always GetMap
        // (the client must not trigger GetFeatureInfo/GetCapabilities under our credentials).
        $params['SERVICE'] = 'WMS';
        $params['REQUEST'] = 'GetMap';
        $params['VERSION'] = (string) ($layer['version'] ?? '1.3.0');
        $params['LAYERS']  = (string) ($layer['layers'] ?? '');
        $params['FORMAT']  = (string) ($layer['format'] ?? 'image/png');

        $auth = is_array($layer['proxy_auth'] ?? null) ? $layer['proxy_auth'] : [];
        if (!empty($auth['extra_query']) && is_array($auth['extra_query'])) {
            foreach ($auth['extra_query'] as $k => $v) {
                $params[$k] = (string) $v;
            }
        }

        $base = (string) $layer['url'];
        $url = $base . (strpos($base, '?') !== false ? '&' : '?') . http_build_query($params);

        $this->streamUpstream($url, $auth);
    }

    // --------------------------------------------------------- helpers -------

    /** Fallback list from config/layers.php (when the DB table is missing / empty). */
    private function apiLayersFromConfig(): array
    {
        $layers = require APP_ROOT . '/config/layers.php';
        $safe = [];
        foreach ($layers as $layer) {
            if (!is_array($layer) || empty($layer['id'])) {
                continue;
            }
            $safe[] = $this->sanitizeLayer($layer);
        }
        return $safe;
    }

    private function sanitizeLayer(array $layer): array
    {
        $expose = [
            'id', 'title', 'type', 'url', 'url_template', 'subdomains', 'layers',
            'format', 'transparent', 'version', 'styles', 'crs', 'attribution',
            'min_zoom', 'max_zoom', 'wmts', 'projection',
        ];
        $out = [];
        foreach ($expose as $key) {
            if (array_key_exists($key, $layer)) {
                $out[$key] = $layer[$key];
            }
        }

        $isProxied = !empty($layer['proxy']);
        $out['proxy'] = $isProxied;

        if (($layer['type'] ?? null) === 'wms') {
            // For proxied layers the URL points to our server – the credentials stay hidden.
            $out['url'] = $isProxied
                ? 'api/wms-proxy?layer=' . rawurlencode((string) $layer['id'])
                : (string) ($layer['url'] ?? '');
        }
        return $out;
    }

    private function findLayer(string $id): ?array
    {
        $layers = require APP_ROOT . '/config/layers.php';
        foreach ($layers as $layer) {
            if (is_array($layer) && ($layer['id'] ?? null) === $id) {
                return $layer;
            }
        }
        return null;
    }

    private function streamUpstream(string $url, array $auth): void
    {
        $type = $auth['type'] ?? null;
        $basicUser = (string) ($auth['user'] ?? '');
        $basicPass = (string) ($auth['pass'] ?? '');

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_MAXFILESIZE    => self::MAX_RESPONSE_BYTES,
                CURLOPT_HTTPHEADER     => ['Accept: image/*'],
            ]);
            if ($type === 'basic' && $basicUser !== '') {
                curl_setopt($ch, CURLOPT_USERPWD, $basicUser . ':' . $basicPass);
            }
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($body === false || $status >= 400 || $status === 0) {
                $this->proxyError($status);
            }
        } else {
            $headers = "Accept: image/*\r\n";
            if ($type === 'basic' && $basicUser !== '') {
                $headers .= 'Authorization: Basic ' . base64_encode($basicUser . ':' . $basicPass) . "\r\n";
            }
            $ctx = stream_context_create(['http' => [
                'method' => 'GET', 'header' => $headers, 'timeout' => 20, 'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx, 0, self::MAX_RESPONSE_BYTES);
            $contentType = 'image/png';
            if (isset($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (stripos($h, 'Content-Type:') === 0) {
                        $contentType = trim(substr($h, 13));
                    }
                }
            }
            if ($body === false) {
                $this->proxyError(502);
            }
        }

        if (!headers_sent()) {
            header('Content-Type: ' . ($contentType !== '' ? $contentType : 'image/png'));
            header('Cache-Control: private, max-age=3600');
        }
        echo $body;
        exit;
    }

    private function proxyError(int $status): void
    {
        error_log('WMS proxy failed, status=' . $status);
        if (!headers_sent()) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Failed to load the tile from WMS.';
        exit;
    }
}

<?php
declare(strict_types=1);

/**
 * Fetching and parsing GetCapabilities for WMS/WMTS layer setup + obtaining the proj4 CRS definition.
 *
 * Called ONLY in the admin (Auth::requireAdmin) – the result is stored in map_layers and is never
 * called while the map is running (the runtime builds the OL source purely from the stored configuration).
 *
 * The preview (capabilities endpoint) returns raw XML to the client – the frontend parses it with the
 * OpenLayers parser (WMSCapabilities/WMTSCapabilities) and computes the grid. Here on the server we parse
 * only for STRICT validation on save: we verify that the chosen layer (and for WMTS also matrixSet/format/style)
 * actually exists in the capabilities – so a layer cannot be saved "from memory" without GetCapabilities.
 */
final class CapabilitiesService
{
    private const CONNECT_TIMEOUT    = 8;
    private const TIMEOUT            = 25;
    private const MAX_RESPONSE_BYTES = 8388608; // 8 MB (GetCapabilities is typically kB to a few MB)
    private const USER_AGENT         = 'GeodataCollector-Setup/1.0';

    // ---------------------------------------------------------------- fetch ---

    /** Downloads raw GetCapabilities XML from a safely validated URL. @throws HttpException */
    public function fetchCapabilities(string $baseUrl, string $service): string
    {
        $service = strtoupper(trim($service));
        if ($service !== 'WMS' && $service !== 'WMTS') {
            $this->fail('caps_service_invalid', 'Only WMS/WMTS is allowed.');
        }
        $url = $this->buildCapabilitiesUrl($baseUrl, $service);
        $this->assertUrlAllowed($url);
        $xml = $this->httpGet($url);
        if (trim($xml) === '') {
            $this->fail('caps_empty_response', 'The service returned no data.', 502);
        }
        return $xml;
    }

    /** Downloads the proj4 CRS definition from epsg.io (dynamically for any EPSG code). null = not found. */
    public function fetchProj4Def(string $epsg): ?string
    {
        if (!preg_match('/^EPSG:(\d{3,7})$/i', trim($epsg), $m)) {
            $this->fail('epsg_code_invalid', 'Invalid EPSG code.');
        }
        $url = 'https://epsg.io/' . $m[1] . '.proj4';
        $this->assertUrlAllowed($url);
        $def = trim($this->httpGet($url));
        // epsg.io returns either a proj4 string, or empty/HTML for an unknown code.
        if ($def === '' || stripos($def, '+proj=') === false) {
            return null;
        }
        return $def;
    }

    private function buildCapabilitiesUrl(string $base, string $service): string
    {
        $base = trim($base);
        if (!preg_match('#^https?://#i', $base)) {
            $this->fail('caps_url_scheme', 'The URL must start with http:// or https://.');
        }
        $p = parse_url($base);
        if ($p === false || empty($p['host'])) {
            $this->fail('caps_url_invalid', 'Invalid URL.');
        }
        // Leave a RESTful WMTSCapabilities.xml as is (it already is a GetCapabilities document).
        if (preg_match('/WMTSCapabilities\.xml$/i', $p['path'] ?? '')) {
            return $base;
        }
        parse_str($p['query'] ?? '', $q);
        $q = array_change_key_case($q, CASE_UPPER);
        $q['SERVICE'] = $service;
        $q['REQUEST'] = 'GetCapabilities';
        $q['VERSION'] = $q['VERSION'] ?? ($service === 'WMS' ? '1.3.0' : '1.0.0');
        $authority = strtolower($p['scheme']) . '://' . $p['host'] . (isset($p['port']) ? ':' . (int) $p['port'] : '');
        return $authority . ($p['path'] ?? '') . '?' . http_build_query($q);
    }

    /** SSRF guard: http/https only; host must not be local/internal; checks ALL resolved IPs. */
    private function assertUrlAllowed(string $url): void
    {
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            $this->fail('caps_url_invalid', 'Invalid URL.');
        }
        if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            $this->fail('caps_url_scheme', 'Only http:// and https:// are allowed.');
        }
        $host = trim($p['host'], '[]');
        if (preg_match('/(^|\.)(localhost|local|internal|intranet)$/i', $p['host'])) {
            $this->fail('caps_host_forbidden', 'The target address is not allowed.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertIpAllowed($host);
            return;
        }
        $ips = $this->resolveAll($host);
        if ($ips === []) {
            $this->fail('caps_host_unresolved', 'The hostname cannot be resolved.');
        }
        foreach ($ips as $ip) {
            $this->assertIpAllowed($ip);
        }
    }

    private function assertIpAllowed(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $this->fail('caps_ip_private', 'The target IP is in a private/reserved range.');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['127.0.0.0', 8],
                      ['169.254.0.0', 16], ['100.64.0.0', 10], ['0.0.0.0', 8]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    $this->fail('caps_ip_private', 'The target IP is in an internal range.');
                }
            }
        }
    }

    private function resolveAll(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
            if (!empty($r['ip'])) { $ips[] = $r['ip']; }
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
        }
        if ($ips === []) {
            $r = gethostbynamel($host);
            if (is_array($r)) { $ips = $r; }
        }
        return array_values(array_unique($ips));
    }

    /** Download – curl with a fallback to file_get_contents (modeled on LayerController::streamUpstream). No redirects. */
    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => ['Accept: text/xml, application/xml, text/plain'],
            ]);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $status >= 400 || $status === 0) {
                $this->fail('caps_http_status', 'Download failed (HTTP ' . $status . ').', 502, ['status' => $status]);
            }
            return substr((string) $body, 0, self::MAX_RESPONSE_BYTES);
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Accept: text/xml, application/xml, text/plain\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
            'timeout'       => self::TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx, 0, self::MAX_RESPONSE_BYTES);
        if ($body === false) {
            $this->fail('caps_download_failed', 'Failed to download the capabilities.', 502);
        }
        return (string) $body;
    }

    // --------------------------------------------------------------- parse ---

    private function loadXml(string $xml): DOMXPath
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET); // no network entities (XXE off; PHP 8 doesn't load external entities)
        libxml_use_internal_errors($prev);
        if ($ok === false || $doc->documentElement === null) {
            $this->fail('caps_not_xml', 'The response is not valid XML (GetCapabilities).');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('wms', 'http://www.opengis.net/wms');
        $xp->registerNamespace('wmts', 'http://www.opengis.net/wmts/1.0');
        $xp->registerNamespace('ows', 'http://www.opengis.net/ows/1.1');
        $xp->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        return $xp;
    }

    /** Parses capabilities into a compact structure (layers + matrixSets for WMTS). Used for both preview and validation. */
    public function parse(string $xml, string $service): array
    {
        $xp = $this->loadXml($xml);
        return strtoupper($service) === 'WMS' ? $this->parseWms($xp) : $this->parseWmts($xp);
    }

    private function parseWms(DOMXPath $xp): array
    {
        $layers = [];
        foreach ($xp->query('//wms:Layer[wms:Name]') as $layer) {
            $name = $this->text($xp, 'wms:Name', $layer);
            if ($name === null) { continue; }
            $crs = [];
            foreach ($xp->query('ancestor-or-self::wms:Layer/wms:CRS', $layer) as $c) {
                $v = trim($c->textContent);
                if ($v !== '') { $crs[$v] = true; }
            }
            $layers[] = [
                'name'  => $name,
                'title' => $this->text($xp, 'wms:Title', $layer) ?? $name,
                'crs'   => array_keys($crs),
            ];
        }
        $formats = [];
        foreach ($xp->query('//wms:Capability/wms:Request/wms:GetMap/wms:Format') as $f) {
            $formats[] = trim($f->textContent);
        }
        $getMap = $xp->evaluate('string(//wms:Capability/wms:Request/wms:GetMap/wms:DCPType/wms:HTTP/wms:Get/wms:OnlineResource/@xlink:href)');
        return [
            'service' => 'wms',
            'version' => '1.3.0',
            'getMapUrl' => is_string($getMap) && $getMap !== '' ? $getMap : null,
            'formats' => array_values(array_unique($formats)),
            'layers'  => $layers,
        ];
    }

    private function parseWmts(DOMXPath $xp): array
    {
        $sets = [];
        foreach ($xp->query('//wmts:Contents/wmts:TileMatrixSet') as $set) {
            $id  = $this->text($xp, 'ows:Identifier', $set);
            $crs = $this->text($xp, 'ows:SupportedCRS', $set);
            if ($id === null || $crs === null) { continue; }
            $matrices = [];
            foreach ($xp->query('wmts:TileMatrix', $set) as $m) {
                $tlc = $this->text($xp, 'wmts:TopLeftCorner', $m);
                $parts = $tlc !== null ? preg_split('/\s+/', trim($tlc)) : [];
                $matrices[] = [
                    'id'               => $this->text($xp, 'ows:Identifier', $m),
                    'scaleDenominator' => (float) $this->text($xp, 'wmts:ScaleDenominator', $m),
                    'topLeftCorner'    => count($parts) === 2 ? [(float) $parts[0], (float) $parts[1]] : null,
                    'tileWidth'        => (int) ($this->text($xp, 'wmts:TileWidth', $m) ?? 256),
                    'tileHeight'       => (int) ($this->text($xp, 'wmts:TileHeight', $m) ?? 256),
                    'matrixWidth'      => (int) ($this->text($xp, 'wmts:MatrixWidth', $m) ?? 0),
                    'matrixHeight'     => (int) ($this->text($xp, 'wmts:MatrixHeight', $m) ?? 0),
                ];
            }
            $sets[$id] = ['identifier' => $id, 'supportedCRS' => $crs, 'matrices' => $matrices];
        }

        $kvp = $xp->evaluate('string(//ows:OperationsMetadata/ows:Operation[@name="GetTile"]/ows:DCP/ows:HTTP/ows:Get[ows:Constraint/ows:AllowedValues/ows:Value="KVP"]/@xlink:href)');
        if (!is_string($kvp) || $kvp === '') {
            $kvp = $xp->evaluate('string(//ows:OperationsMetadata/ows:Operation[@name="GetTile"]/ows:DCP/ows:HTTP/ows:Get/@xlink:href)');
        }

        $layers = [];
        foreach ($xp->query('//wmts:Contents/wmts:Layer') as $layer) {
            $id = $this->text($xp, 'ows:Identifier', $layer);
            if ($id === null) { continue; }
            $formats = [];
            foreach ($xp->query('wmts:Format', $layer) as $f) { $formats[] = trim($f->textContent); }
            $styles = [];
            $defStyle = null;
            foreach ($xp->query('wmts:Style', $layer) as $s) {
                $sid = $this->text($xp, 'ows:Identifier', $s);
                if ($sid === null) { continue; }
                $styles[] = $sid;
                if ($s->getAttribute('isDefault') === 'true' && $defStyle === null) { $defStyle = $sid; }
            }
            $matrixSets = [];
            foreach ($xp->query('wmts:TileMatrixSetLink/wmts:TileMatrixSet', $layer) as $ms) {
                $matrixSets[] = trim($ms->textContent);
            }
            $resources = [];
            foreach ($xp->query('wmts:ResourceURL[@resourceType="tile"]', $layer) as $r) {
                $resources[] = ['format' => $r->getAttribute('format'), 'template' => $r->getAttribute('template')];
            }
            $layers[] = [
                'identifier'        => $id,
                'title'             => $this->text($xp, 'ows:Title', $layer) ?? $id,
                'formats'           => array_values(array_unique($formats)),
                'styles'            => $styles,
                'defaultStyle'      => $defStyle ?? ($styles[0] ?? 'default'),
                'matrixSets'        => $matrixSets,
                'resourceTemplates' => $resources,
            ];
        }
        return [
            'service' => 'wmts',
            'version' => '1.0.0',
            'kvpGetTile' => is_string($kvp) && $kvp !== '' ? $kvp : null,
            'tileMatrixSets' => array_values($sets),
            'layers' => $layers,
        ];
    }

    /**
     * Strict validation of the choice against FRESH capabilities (called on create/update).
     * @throws HttpException 422 if the layer/matrixSet/format/style does not exist in the capabilities.
     */
    public function validateChoice(string $baseUrl, string $service, array $choice): void
    {
        $service = strtoupper($service);
        $parsed = $this->parse($this->fetchCapabilities($baseUrl, $service), $service);

        if ($service === 'WMS') {
            if ($this->findBy($parsed['layers'], 'name', (string) ($choice['layer'] ?? '')) === null) {
                $this->fail('wms_layer_not_in_caps', 'The chosen WMS layer does not exist in GetCapabilities - load the layers and pick from the list.');
            }
            return;
        }
        $layer = $this->findBy($parsed['layers'], 'identifier', (string) ($choice['layer'] ?? ''));
        if ($layer === null) {
            $this->fail('wmts_layer_not_in_caps', 'The chosen WMTS layer does not exist in GetCapabilities - load the layers and pick from the list.');
        }
        $ms = (string) ($choice['matrix_set'] ?? '');
        if (!in_array($ms, $layer['matrixSets'], true)) {
            $this->fail('wmts_matrixset_unsupported', 'The layer does not support the chosen TileMatrixSet "' . $ms . '".', 422, ['value' => $ms]);
        }
        $fmt = (string) ($choice['format'] ?? '');
        if ($layer['formats'] !== [] && !in_array($fmt, $layer['formats'], true)) {
            $this->fail('wmts_format_unsupported', 'The layer does not support the format "' . $fmt . '".', 422, ['value' => $fmt]);
        }
        $style = (string) ($choice['style'] ?? '');
        if ($style !== '' && $layer['styles'] !== [] && !in_array($style, $layer['styles'], true)) {
            $this->fail('wmts_style_unsupported', 'The layer does not support the style "' . $style . '".', 422, ['value' => $style]);
        }
    }

    private function text(DOMXPath $xp, string $expr, DOMNode $ctx): ?string
    {
        $v = $xp->evaluate('string(' . $expr . ')', $ctx);
        $v = is_string($v) ? trim($v) : '';
        return $v !== '' ? $v : null;
    }

    private function findBy(array $rows, string $key, string $val): ?array
    {
        foreach ($rows as $r) {
            if (($r[$key] ?? null) === $val) { return $r; }
        }
        return null;
    }

    private function fail(string $code, string $message, int $status = 422, array $params = []): void
    {
        throw new HttpException($message, $status, $code, [], $params);
    }
}

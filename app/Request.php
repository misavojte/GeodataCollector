<?php
declare(strict_types=1);

/**
 * HTTP request wrapper for the API.
 */
final class Request
{
    public string $method;
    public string $route;          // normalized path, e.g. "/features/12"
    public array $query;
    private ?array $jsonCache = null;

    // JSON body size cap: 50k points + attributes fit comfortably. Prevents a hostile/buggy
    // client from sending a huge body that PHP would buffer and decode (OOM on shared
    // hosting). Geometry is further limited by GeometryService::MAX_POINTS; this is a coarse upper safeguard.
    private const MAX_BODY_BYTES = 2 * 1024 * 1024;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // The path arrives from the .htaccess rewrite as ?__route=features/12
        $route = (string) ($_GET['__route'] ?? '');
        $this->route = '/' . trim($route, '/');

        $this->query = $_GET;
        unset($this->query['__route']);
    }

    /** Request body as an array (JSON). */
    public function body(): array
    {
        if ($this->jsonCache === null) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && strlen($raw) > self::MAX_BODY_BYTES) {
                throw new HttpException('Request too large.', 413, 'payload_too_large');
            }
            $data = $raw !== false && $raw !== '' ? json_decode($raw, true, 64) : null;
            $this->jsonCache = is_array($data) ? $data : [];
        }
        return $this->jsonCache;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    /** @param mixed $default @return mixed */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

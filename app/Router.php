<?php
declare(strict_types=1);

/**
 * Minimalist router. Patterns support parameters in the form {id}.
 */
final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [strtoupper($method), $pattern, $handler];
    }

    public function get(string $p, callable $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void   { $this->add('POST', $p, $h); }
    public function put(string $p, callable $h): void    { $this->add('PUT', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    /** @return mixed */
    public function dispatch(Request $req)
    {
        $matchedPath = false;
        foreach ($this->routes as [$method, $pattern, $handler]) {
            if (!preg_match($this->toRegex($pattern), $req->route, $m)) {
                continue;
            }
            $matchedPath = true;
            if ($method !== $req->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($req, $params);
        }

        if ($matchedPath) {
            throw new HttpException('Method not allowed for this endpoint.', 405, 'method_not_allowed');
        }
        throw new HttpException('Unknown API endpoint.', 404, 'endpoint_not_found');
    }

    private function toRegex(string $pattern): string
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }
}

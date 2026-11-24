<?php
declare(strict_types=1);

/**
 * Sending API JSON responses.
 */
final class Response
{
    /** @param mixed $data */
    public static function json($data, int $status = 200): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"error":{"message":"Response serialization failed.","code":"server_error"}}';
            $status = 500;
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');

            // Body compression (gzip) – JSON shrinks roughly ~10×, the biggest win on the wire.
            // Only done for non-trivial bodies and only when: (1) the client supports gzip, (2) the server
            // doesn't already compress on its own (otherwise we'd get double compression and a corrupted body).
            $accept = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
            $serverGzips = ini_get('zlib.output_compression')
                || in_array('ob_gzhandler', ob_list_handlers(), true);
            if (strlen($json) > 1024
                && stripos($accept, 'gzip') !== false
                && function_exists('gzencode')
                && !$serverGzips) {
                $gz = gzencode($json, 6);
                if ($gz !== false) {
                    header('Content-Encoding: gzip');
                    header('Vary: Accept-Encoding');
                    $json = $gz;
                }
            }
            header('Content-Length: ' . strlen($json));
        }
        echo $json;
        exit;
    }

    public static function error(string $message, int $status = 400, ?string $code = null, array $params = []): void
    {
        $error = ['message' => $message, 'code' => $code];
        if ($params) {
            $error['params'] = $params;
        }
        self::json(['error' => $error], $status);
    }
}

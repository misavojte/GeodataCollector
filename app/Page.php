<?php
declare(strict_types=1);

/**
 * Helper for HTML pages: security headers (CSP with nonce) and escaping.
 */
final class Page
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    /** Sets a Content-Security-Policy matching the nonce. */
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $nonce = self::nonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            // Server-rendered pages (login/setup) load only their own assets; the single inline block
            // runs via nonce. No external scripts (frontend = OpenLayers bundled locally).
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            // WMS tiles may come from various https servers + data: icons.
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "font-src 'self' data:",
            "object-src 'none'",
        ]);
        header('Content-Security-Policy: ' . $csp);
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Content-Type-Options: nosniff');
    }

    /** Escape for HTML output. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Safely embed data into a <script> block. */
    public static function json($data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}

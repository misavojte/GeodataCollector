<?php
declare(strict_types=1);

/**
 * CSRF protection. The token is bound to the session.
 *  - Pages (login/logout): hidden form field.
 *  - JSON API: X-CSRF-Token header (sent by the SvelteKit client, frontend/src/lib/api.ts).
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function validate(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && !empty($_SESSION['csrf'])
            && hash_equals((string) $_SESSION['csrf'], $token);
    }

    public static function requireValid(?string $token): void
    {
        if (!self::validate($token)) {
            throw new HttpException('Invalid security token. Please reload the page.', 419, 'csrf');
        }
    }
}

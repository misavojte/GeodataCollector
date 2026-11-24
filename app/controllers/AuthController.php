<?php
declare(strict_types=1);

/**
 * Signed-in user info for the frontend.
 *   GET /api/me   current user + CSRF token
 *
 * Sign-in and sign-out are handled by the server-side pages login.php / logout.php
 * (robust even without JavaScript).
 */
final class AuthController
{
    public function me(Request $req): void
    {
        $user = Auth::requireAuth();
        Response::json([
            'user' => [
                'id'           => (int) $user['id'],
                'username'     => $user['username'],
                'display_name' => $user['display_name'],
                'role'         => $user['role'],
            ],
            'csrf_token' => Csrf::token(),
            // Branding from config.php (single source of the name and subtitle for the SPA header and the sign-in page).
            'app' => [
                'name'     => (string) Config::get('app.name', 'Geodata Collector'),
                'subtitle' => (string) Config::get('app.subtitle', ''),
                'default_language' => Config::defaultLanguage(),
            ],
            // Map configuration for the frontend (the SPA loads it at startup).
            'map' => [
                'center'    => Config::get('map.default_center', [49.8, 15.5]),
                'zoom'      => (int) Config::get('map.default_zoom', 11),
                'minZoom'   => (int) Config::get('map.min_zoom', 6),
                'maxZoom'   => (int) Config::get('map.max_zoom', 19),
                'maxBounds' => Config::get('map.max_bounds', null),
            ],
        ]);
    }
}

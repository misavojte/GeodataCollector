<?php
/**
 * Sample configuration.
 *
 * 1) Copy this file to  config/config.php
 * 2) Fill in the database credentials (from your hosting control panel).
 * 3) After creating the administrator account, set 'setup_enabled' => false.
 *
 * config.php is in .gitignore – NEVER commit it (it contains the DB password).
 */

return [

    'app' => [
        'name'  => 'Geodata Collector',
        // Subtitle shown next to the app name (in the app header and on the login page). Empty = hidden.
        'subtitle' => 'Collection and records of historical features',
        // Default UI language ('en' or 'cs') for users who have not picked one in the menu yet.
        'default_language' => 'en',
        // Enable only while debugging (prints errors). ALWAYS false in production.
        'debug' => false,
        // Used to include error details in API JSON responses (only in debug mode).
        'timezone' => 'Europe/Prague',
    ],

    'db' => [
        'host'    => 'localhost',          // on shared hosting often a separate DB server (see control panel)
        'port'    => 3306,
        'name'    => 'geodata_collector', // database name
        'user'    => 'db_user',            // database user
        'pass'    => 'CHANGE_ME',         // database password
        'charset' => 'utf8mb4',
    ],

    'security' => [
        // Session cookie name. WARNING: must stay 'PHPSESSID'! Some hosting
        // proxies/CDNs only pass well-known session cookie names on GET requests;
        // a custom name was dropped by the proxy and nobody could log in
        // (the login POST succeeded, but the next GET no longer saw the session). Verified 2026-07-12.
        'session_name'  => 'PHPSESSID',
        // true on HTTPS. Set to false on plain HTTP.
        'cookie_secure' => true,
        // Maximum number of failed logins from ONE IP within the window below. The limit is
        // intentionally per-IP (not per-account) – a per-account lock would let anyone lock out someone else's account (DoS).
        'login_max_attempts' => 8,
        // Window for counting attempts (in seconds).
        'login_window_seconds' => 900,
    ],

    'setup' => [
        // Default is DISABLED (safe). Enable (true) only TEMPORARILY for
        // tools/create_admin.php and also set setup_token below.
        // AFTER creating the accounts, set back to false (or delete create_admin.php).
        'setup_enabled' => false,
        // REQUIRED when setup_enabled = true: a secret token. create_admin.php
        // runs only if the token is non-empty and matches ?token=... in the URL.
        'setup_token' => '',
    ],

    'map' => [
        // Default map view (center of the Czech Republic) and zoom.
        'default_center' => [49.8, 15.5],        // [lat, lon]
        'default_zoom'   => 7,
        'min_zoom'       => 6,
        'max_zoom'       => 19,
        // Map extent limit (roughly the Czech Republic) – [[south, west],[north, east]].
        'max_bounds'     => [[48.3, 11.8], [51.4, 19.1]],
    ],
];

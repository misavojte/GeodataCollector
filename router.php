<?php
declare(strict_types=1);

/**
 * DEVELOPMENT server for local testing:
 *
 *     php -S 127.0.0.1:8088 router.php
 *
 * Serves the static SvelteKit build from frontend/build + the PHP backend (api.php,
 * login.php, logout.php). NOT USED in PRODUCTION – there Apache does the routing
 * (DirectoryIndex index.html + .htaccess rewrite of /api/*).
 */

$root  = __DIR__;
$build = $root . '/frontend/build';
$uri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 1) API
if (preg_match('#^/api/(.*)$#', $uri, $m)) {
    $_GET['__route'] = $m[1];
    require $root . '/api.php';
    return true;
}

// 2) PHP pages in the root and subfolders (login.php, logout.php, tools/...)
if (preg_match('#\.php$#', $uri) && is_file($root . $uri)) {
    return false; // served by the built-in PHP server
}

// 3) Static files: build first (SvelteKit), then root (assets/login.css, logo.svg)
$mimes = [
    'js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'json' => 'application/json', 'ico' => 'image/x-icon', 'woff' => 'font/woff',
    'woff2' => 'font/woff2', 'webmanifest' => 'application/manifest+json', 'txt' => 'text/plain'
];
if ($uri !== '/') {
    foreach ([$build . $uri, $root . $uri] as $path) {
        if (is_file($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (isset($mimes[$ext])) {
                header('Content-Type: ' . $mimes[$ext] . ($ext === 'css' || $ext === 'js' || $ext === 'svg' ? '; charset=utf-8' : ''));
            }
            readfile($path);
            return true;
        }
    }
}

// 4) Root → SPA; any other non-existent path → friendly 404 (parity with production).
header('Content-Type: text/html; charset=utf-8');
if ($uri === '/' || $uri === '/index.html') {
    readfile($build . '/index.html');
} else {
    http_response_code(404);
    $page = @file_get_contents($root . '/404.html');
    echo $page !== false ? str_replace('__BASE__', '', $page) : 'Page not found (404).';
}
return true;

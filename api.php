<?php
declare(strict_types=1);

/**
 * REST API front controller.
 * Accessed via /api/<path> (rewritten in .htaccess to api.php?__route=<path>).
 */

define('APP_ROOT', __DIR__);
require APP_ROOT . '/app/bootstrap.php';

$request = new Request();

try {
    // For read requests: if the client aborts the connection (e.g. AbortController during fast map panning/zooming),
    // stop PHP execution and free the worker for the next request (prevents exhausting the hosting's process limit).
    if ($request->method === 'GET') {
        ignore_user_abort(false);
        if (connection_aborted()) {
            exit;
        }
    }

    // CSRF check on all state-changing methods (the SvelteKit client frontend/src/lib/api.ts sends the token in a header).
    if (in_array($request->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        Csrf::requireValid($request->header('X-CSRF-Token'));
    }

    $router = new Router();

    // --- User ---
    $router->get('/me', fn($req) => (new AuthController())->me($req));

    // --- Users (admin only) ---
    $router->get('/users', fn($req) => (new UserController())->index($req));
    $router->post('/users', fn($req) => (new UserController())->store($req));
    $router->put('/users/{id}', fn($req, $p) => (new UserController())->update($req, $p));

    // --- Dynamic fields ---
    $router->get('/fields', fn($req) => (new FieldController())->index($req));
    $router->post('/fields', fn($req) => (new FieldController())->store($req));
    $router->post('/fields/reorder', fn($req) => (new FieldController())->reorder($req));
    $router->put('/fields/{id}', fn($req, $p) => (new FieldController())->update($req, $p));
    $router->delete('/fields/{id}', fn($req, $p) => (new FieldController())->destroy($req, $p));

    // --- Map layers ---
    $router->get('/layers', fn($req) => (new LayerController())->index($req));
    $router->get('/wms-proxy', fn($req) => (new LayerController())->wmsProxy($req));

    // Layer management (admin only)
    $router->get('/map-layers', fn($req) => (new LayerController())->manageIndex($req));
    $router->post('/map-layers', fn($req) => (new LayerController())->store($req));
    $router->post('/map-layers/reorder', fn($req) => (new LayerController())->reorder($req));
    $router->post('/map-layers/capabilities', fn($req) => (new LayerController())->capabilities($req));
    $router->get('/map-layers/crs', fn($req) => (new LayerController())->crs($req));
    $router->put('/map-layers/{id}', fn($req, $p) => (new LayerController())->update($req, $p));
    $router->delete('/map-layers/{id}', fn($req, $p) => (new LayerController())->destroy($req, $p));

    // --- Collected geometries ---
    $router->get('/features', fn($req) => (new FeatureController())->index($req));
    // NOTE: /features/version must come BEFORE /features/{id}, otherwise the {id} pattern would capture it.
    $router->get('/features/version', fn($req) => (new FeatureController())->version($req));
    $router->post('/features/export', fn($req) => (new FeatureController())->export($req));
    $router->get('/features/{id}', fn($req, $p) => (new FeatureController())->show($req, $p));
    $router->post('/features', fn($req) => (new FeatureController())->store($req));
    $router->put('/features/{id}', fn($req, $p) => (new FeatureController())->update($req, $p));
    $router->delete('/features/{id}', fn($req, $p) => (new FeatureController())->destroy($req, $p));

    // Version history / restore (admin only)
    $router->get('/features/{id}/revisions', fn($req, $p) => (new FeatureController())->revisions($req, $p));
    $router->post('/features/{id}/restore', fn($req, $p) => (new FeatureController())->restore($req, $p));
    $router->get('/changes', fn($req) => (new FeatureController())->recentChanges($req));

    // --- Attachments (photos / documents) ---
    $router->post('/uploads', fn($req) => (new AttachmentController())->upload($req));
    $router->get('/attachments/{id}', fn($req, $p) => (new AttachmentController())->show($req, $p));

    // --- Reference points (GCP) ---
    $router->get('/gcp', fn($req) => (new GcpController())->index($req));
    $router->get('/gcp/next-label', fn($req) => (new GcpController())->nextLabel($req));
    $router->get('/gcp/{id}', fn($req, $p) => (new GcpController())->show($req, $p));
    $router->post('/gcp', fn($req) => (new GcpController())->store($req));
    $router->put('/gcp/{id}', fn($req, $p) => (new GcpController())->update($req, $p));
    $router->delete('/gcp/{id}', fn($req, $p) => (new GcpController())->destroy($req, $p));

    $router->dispatch($request);

} catch (HttpException $e) {
    $payload = ['error' => ['message' => $e->getMessage(), 'code' => $e->errorCode]];
    if ($e->params) {
        $payload['error']['params'] = $e->params;
    }
    if ($e->details) {
        $payload['error']['fields'] = $e->details;
    }
    Response::json($payload, $e->status);

} catch (PDOException $e) {
    // Database errors are NEVER sent to the user verbatim – SQLSTATE and the like belong in the log,
    // not in a toast (not even in debug mode; this is how error 1207 once leaked in production).
    // The MySQL error number does go out in `code` (db_2006 etc.) – it reveals nothing sensitive
    // and the browser console shows right away WHICH error occurred in production.
    $errno = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    if ($errno === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
        $errno = (int) $m[1];
    }
    DbLog::write('api /' . (string) ($_GET['__route'] ?? '') . ' UNHANDLED (errno ' . $errno . '): '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error('A temporary database error occurred. Please try again.', 500, $errno > 0 ? 'db_' . $errno : 'db_error');

} catch (Throwable $e) {
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $message = (bool) Config::get('app.debug', false)
        ? $e->getMessage()
        : 'A server error occurred. Please try again.';
    Response::error($message, 500, 'server_error');
}

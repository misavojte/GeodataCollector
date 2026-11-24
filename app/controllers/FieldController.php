<?php
declare(strict_types=1);

/**
 * Dynamic field definitions.
 *   GET    /api/fields          active fields for the form (signed-in users)
 *   GET    /api/fields?all=1    all fields (admin only)
 *   POST   /api/fields          create a definition (admin only)
 *   PUT    /api/fields/{id}      update a definition (admin only)
 *   DELETE /api/fields/{id}      deactivate a definition (admin only)
 *
 * The write operations are prepared for a future admin interface – functional,
 * but without a dedicated screen in the UI yet.
 */
final class FieldController
{
    private FieldService $fields;

    public function __construct()
    {
        $this->fields = new FieldService();
    }

    public function index(Request $req): void
    {
        Auth::requireAuth();

        if ((string) $req->query('all', '') === '1') {
            Auth::requireAdmin();
            Response::json(['fields' => $this->fields->getAllDefinitions()]);
        }

        Response::json(['fields' => $this->fields->getActiveDefinitions()]);
    }

    public function store(Request $req): void
    {
        $user = Auth::requireAdmin();
        $id = $this->fields->createDefinition($req->body());
        AuditLog::record((int) $user['id'], 'field.create', 'field', $id, null, $req->clientIp());
        Response::json(['id' => $id], 201);
    }

    public function update(Request $req, array $params): void
    {
        $user = Auth::requireAdmin();
        $id = (int) $params['id'];
        $this->fields->updateDefinition($id, $req->body());
        AuditLog::record((int) $user['id'], 'field.update', 'field', $id, null, $req->clientIp());
        Response::json(['updated' => true, 'id' => $id]);
    }

    public function destroy(Request $req, array $params): void
    {
        $user = Auth::requireAdmin();
        $id = (int) $params['id'];
        $this->fields->deactivateDefinition($id);
        AuditLog::record((int) $user['id'], 'field.deactivate', 'field', $id, null, $req->clientIp());
        Response::json(['deactivated' => true, 'id' => $id]);
    }

    public function reorder(Request $req): void
    {
        $user = Auth::requireAdmin();
        $body = $req->body();
        $order = isset($body['order']) && is_array($body['order']) ? $body['order'] : [];
        $this->fields->reorder($order);
        AuditLog::record((int) $user['id'], 'field.reorder', 'field', null, null, $req->clientIp());
        Response::json(['reordered' => true]);
    }
}

<?php
declare(strict_types=1);

/**
 * User account management (admin only).
 *   GET  /api/users          list accounts
 *   POST /api/users          create an account
 *   PUT  /api/users/{id}     update (name, e-mail, role, activation, password reset)
 *
 * There is no delete – an account is "removed" by deactivation (PUT is_active=false), because of
 * foreign keys and to preserve the authorship of records.
 */
final class UserController
{
    private UserService $users;

    public function __construct()
    {
        $this->users = new UserService();
    }

    public function index(Request $req): void
    {
        Auth::requireAdmin();
        Response::json(['users' => $this->users->getAll()]);
    }

    public function store(Request $req): void
    {
        $admin = Auth::requireAdmin();
        $id = $this->users->create($req->body());
        AuditLog::record((int) $admin['id'], 'user.create', 'user', $id, null, $req->clientIp());
        Response::json(['id' => $id], 201);
    }

    public function update(Request $req, array $params): void
    {
        $admin = Auth::requireAdmin();
        $id = (int) $params['id'];
        $this->users->update($id, $req->body(), (int) $admin['id']);
        AuditLog::record((int) $admin['id'], 'user.update', 'user', $id, null, $req->clientIp());
        Response::json(['updated' => true, 'id' => $id]);
    }
}

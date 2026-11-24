<?php
declare(strict_types=1);

/**
 * Record attachments (photos / documents).
 *   POST /api/uploads                 upload a single file (multipart: file, field_key)
 *   GET  /api/attachments/{id}?v=t|d  file (t = thumbnail, d = display size, no v = original)
 */
final class AttachmentController
{
    public function upload(Request $req): void
    {
        $user = Auth::requireAuth();
        // Photo processing can take seconds – don't block the user's other requests with the session lock.
        session_write_close();

        // post_max_size exceeded: PHP silently discards the whole body ($_POST and $_FILES are both empty).
        if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new HttpException('The file is too large.', 413, 'upload_too_large');
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            throw new HttpException('No file was sent.', 422, 'upload_failed');
        }

        $key = (string) ($_POST['field_key'] ?? '');
        $def = null;
        foreach ((new FieldService())->getActiveDefinitions() as $d) {
            if ($d['field_key'] === $key && in_array($d['data_type'], ['photos', 'files'], true)) {
                $def = $d;
                break;
            }
        }
        if ($def === null) {
            throw new HttpException('Unknown attachment field.', 422, 'upload_field_invalid');
        }

        $result = (new AttachmentService())->upload($file, $def, (int) $user['id']);
        AuditLog::record((int) $user['id'], 'attachment.upload', 'attachment', $result['id'], ['field' => $key], $req->clientIp());
        Response::json($result, 201);
    }

    public function show(Request $req, array $params): void
    {
        Auth::requireAuth();
        session_write_close(); // thumbnails load concurrently – no session lock

        $service = new AttachmentService();
        $row = $service->findMany([(int) $params['id']])[(int) $params['id']] ?? null;
        if ($row === null) {
            throw new HttpException('File not found.', 404, 'attachment_not_found');
        }
        $service->send($row, (string) $req->query('v', ''));
    }
}

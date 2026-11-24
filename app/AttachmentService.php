<?php
declare(strict_types=1);

/**
 * Feature attachments: photos (fields of type 'photos') and documents (fields of type 'files').
 *  - A file is uploaded right after selection (POST /api/uploads), before the feature is saved.
 *  - features.attributes stores a list [{id,name,size}] under the field key – on save the server
 *    validates it and overwrites it with values from the DB (FieldService). Version history thus works unchanged.
 *  - Files on disk are never deleted (restoring an older feature version must be able to find them).
 */
final class AttachmentService
{
    /** Allowed documents: extension => accepted MIME types (per finfo). */
    private const FILE_TYPES = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'tif'  => ['image/tiff'],
        'tiff' => ['image/tiff'],
    ];

    /** Allowed photos: extension => MIME. HEIC/HEIF (iPhone) is handled by Imagick. */
    private const PHOTO_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif',
    ];

    /** MIME types the browser may display inline (others are downloaded). */
    private const INLINE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public const MAX_PHOTO_BYTES = 50 * 1024 * 1024;
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;
    public const DEFAULT_MAX_FILES = 20;

    public static function storageRoot(): string
    {
        return APP_ROOT . '/storage/uploads';
    }

    /**
     * Accepts an uploaded file for the given field, stores the original (+ variants for photos) and a DB record.
     * @return array{id:int,name:string,size:int}
     */
    public function upload(array $file, array $fieldDef, int $userId): array
    {
        $kind = $fieldDef['data_type'] === 'photos' ? 'photo' : 'file';

        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException('The file is too large.', 413, 'upload_too_large');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new HttpException('The file upload failed.', 422, 'upload_failed');
        }

        $tmp = (string) $file['tmp_name'];
        $size = (int) filesize($tmp);
        $maxBytes = $kind === 'photo' ? self::MAX_PHOTO_BYTES : self::MAX_FILE_BYTES;
        if ($size <= 0 || $size > $maxBytes) {
            throw new HttpException('The file is too large.', 413, 'upload_too_large', [], ['max' => (int) ($maxBytes / 1048576)]);
        }

        $name = $this->cleanName((string) ($file['name'] ?? 'soubor'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

        if ($kind === 'photo') {
            if (!isset(self::PHOTO_TYPES[$ext])) {
                throw new HttpException('Unsupported file type.', 422, 'upload_type_not_allowed');
            }
            // finfo often doesn't recognize HEIC (application/octet-stream) – decoding verifies it later.
            $heic = in_array($ext, ['heic', 'heif'], true);
            if (!$heic && strpos($mime, 'image/') !== 0) {
                throw new HttpException('Unsupported file type.', 422, 'upload_type_not_allowed');
            }
            if ($heic || $mime === 'application/octet-stream') {
                $mime = self::PHOTO_TYPES[$ext];
            }
        } else {
            if (!isset(self::FILE_TYPES[$ext]) || !in_array($mime, self::FILE_TYPES[$ext], true)) {
                throw new HttpException('Unsupported file type.', 422, 'upload_type_not_allowed');
            }
        }

        // Target: storage/uploads/YYYY/MM/<random key>.<ext>
        $rel = date('Y/m') . '/' . bin2hex(random_bytes(16));
        $base = self::storageRoot() . '/' . $rel;
        $this->ensureDir(dirname($base));
        $origPath = $base . '.' . $ext;
        if (!move_uploaded_file($tmp, $origPath)) {
            throw new HttpException('The file could not be stored.', 500, 'upload_store_failed');
        }

        $meta = ['width' => null, 'height' => null, 'taken_at' => null, 'gps_lat' => null, 'gps_lon' => null];
        if ($kind === 'photo') {
            try {
                $meta = (new ImageService())->process($origPath, $base, $ext);
            } catch (Throwable $e) {
                @unlink($origPath);
                foreach (array_keys(ImageService::VARIANTS) as $s) {
                    @unlink($base . '_' . $s . '.jpg');
                }
                if ($e instanceof HttpException) {
                    throw $e;
                }
                error_log('Photo processing failed: ' . $e->getMessage());
                throw new HttpException('The image could not be processed.', 422, 'upload_image_invalid');
            }
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO attachments
                (field_key, kind, storage_path, ext, original_name, mime, size_bytes, width, height, taken_at, gps_lat, gps_lon, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fieldDef['field_key'], $kind, $rel, $ext, $name, $mime, $size,
            $meta['width'], $meta['height'], $meta['taken_at'], $meta['gps_lat'], $meta['gps_lon'], $userId,
        ]);

        return ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'size' => $size];
    }

    /** @return array<int, array> attachments rows keyed by id */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare("SELECT * FROM attachments WHERE id IN ($in)");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** Links freshly uploaded (still unattached) attachments to a feature. */
    public function linkToFeature(array $ids, int $featureId): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        Database::pdo()
            ->prepare("UPDATE attachments SET feature_id = ? WHERE feature_id IS NULL AND id IN ($in)")
            ->execute(array_merge([$featureId], $ids));
    }

    /** Sends the file to the browser (variant 't' / 'd' for photos, otherwise the original). */
    public function send(array $row, string $variant): void
    {
        $base = self::storageRoot() . '/' . $row['storage_path'];
        $isPhoto = $row['kind'] === 'photo';
        if ($isPhoto && isset(ImageService::VARIANTS[$variant])) {
            $path = $base . '_' . $variant . '.jpg';
            $mime = 'image/jpeg';
            $name = pathinfo((string) $row['original_name'], PATHINFO_FILENAME) . '.jpg';
        } else {
            $path = $base . '.' . $row['ext'];
            $mime = (string) $row['mime'];
            $name = (string) $row['original_name'];
        }
        if (!is_file($path)) {
            throw new HttpException('File not found.', 404, 'attachment_not_found');
        }

        $etag = '"' . $row['id'] . '-' . $variant . '"';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Cache-Control: private, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        $inline = in_array($mime, self::INLINE_MIME, true) && (string) ($_GET['download'] ?? '') !== '1';
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------- helpers ---

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'soubor';
        }
        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 190) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    /** Creates the directory; additionally ensures storage/ has an .htaccess denying direct access. */
    private function ensureDir(string $dir): void
    {
        $storage = APP_ROOT . '/storage';
        if (!is_file($storage . '/.htaccess')) {
            @mkdir($storage, 0755, true);
            @file_put_contents($storage . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new HttpException('The file could not be stored.', 500, 'upload_store_failed');
        }
    }
}

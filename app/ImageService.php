<?php
declare(strict_types=1);

/**
 * Server-side photo processing: produces downscaled JPEG variants from the original
 * (thumbnail + display), fixes rotation according to EXIF and strips EXIF from the variants.
 * The original stays unchanged (archive).
 *
 * Primarily Imagick (also handles HEIC from iPhones and saves memory), with GD as fallback (JPEG/PNG/WebP).
 */
final class ImageService
{
    /** Variants: file suffix => [max. longer side in px, JPEG quality]. */
    public const VARIANTS = [
        't' => [400, 75],   // thumbnail in the feature card
        'd' => [2048, 82],  // full-screen display
    ];

    /**
     * Creates variants next to the original ($base . '_t.jpg', $base . '_d.jpg').
     * @return array{width:int,height:int,taken_at:?string,gps_lat:?float,gps_lon:?float}
     * @throws HttpException 422 if the image cannot be loaded
     */
    public function process(string $origPath, string $base, string $ext): array
    {
        if (extension_loaded('imagick')) {
            return $this->processImagick($origPath, $base);
        }
        if (in_array($ext, ['heic', 'heif'], true)) {
            throw new HttpException('This image format is not supported on the server.', 422, 'upload_image_unsupported');
        }
        return $this->processGd($origPath, $base, $ext);
    }

    // ------------------------------------------------------------- Imagick ---

    private function processImagick(string $origPath, string $base): array
    {
        try {
            // When the limit is exceeded, ImageMagick spills pixels to disk instead of crashing on memory_limit.
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            // For a huge photo the JPEG decoder downscales right away (DCT scaling) – a 48 MPx photo then doesn't
            // take hundreds of MB. Only for large ones: for smaller ones libjpeg would actually ENLARGE the image.
            $size = @getimagesize($origPath);
            $im = new Imagick();
            if ($size !== false && $size[2] === IMAGETYPE_JPEG && max($size[0], $size[1]) > 4096) {
                $im->setOption('jpeg:size', '4096x4096');
            }
            $im->readImage($origPath . '[0]');
        } catch (Throwable $e) {
            throw new HttpException('The image could not be read.', 422, 'upload_image_invalid');
        }

        $meta = $this->exifFromImagick($im);
        $this->orientImagick($im);
        $meta['width'] = $im->getImageWidth();
        $meta['height'] = $im->getImageHeight();

        if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        // Transparency (PNG/WebP) onto a white background – JPEG has no alpha.
        if ($im->getImageAlphaChannel()) {
            $im->setImageBackgroundColor('white');
            $flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->clear();
            $im = $flat;
        }

        // From the largest variant to the smallest – each is downscaled from the previous one (faster).
        $variants = self::VARIANTS;
        uasort($variants, static fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($variants as $suffix => [$max, $quality]) {
            if ($im->getImageWidth() > $max || $im->getImageHeight() > $max) {
                $im->thumbnailImage($max, $max, true);
            }
            $im->stripImage();
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($quality);
            $im->setInterlaceScheme(Imagick::INTERLACE_PLANE); // progressive JPEG
            $im->writeImage($base . '_' . $suffix . '.jpg');
        }
        $im->clear();
        return $meta;
    }

    private function orientImagick(Imagick $im): void
    {
        switch ($im->getImageOrientation()) {
            case Imagick::ORIENTATION_TOPRIGHT:    $im->flopImage(); break;
            case Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage('#000', 180); break;
            case Imagick::ORIENTATION_BOTTOMLEFT:  $im->flipImage(); break;
            case Imagick::ORIENTATION_LEFTTOP:     $im->rotateImage('#000', 90); $im->flopImage(); break;
            case Imagick::ORIENTATION_RIGHTTOP:    $im->rotateImage('#000', 90); break;
            case Imagick::ORIENTATION_RIGHTBOTTOM: $im->rotateImage('#000', -90); $im->flopImage(); break;
            case Imagick::ORIENTATION_LEFTBOTTOM:  $im->rotateImage('#000', -90); break;
        }
        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    private function exifFromImagick(Imagick $im): array
    {
        $p = [];
        try {
            $p = $im->getImageProperties('exif:*');
        } catch (Throwable $e) {
            // no EXIF
        }
        $lat = $this->gpsToFloat($p['exif:GPSLatitude'] ?? null, $p['exif:GPSLatitudeRef'] ?? null);
        $lon = $this->gpsToFloat($p['exif:GPSLongitude'] ?? null, $p['exif:GPSLongitudeRef'] ?? null);
        return [
            'taken_at' => $this->exifDate($p['exif:DateTimeOriginal'] ?? ($p['exif:DateTime'] ?? null)),
            'gps_lat'  => $lat,
            'gps_lon'  => $lon,
        ];
    }

    // ------------------------------------------------------------------ GD ---

    private function processGd(string $origPath, string $base, string $ext): array
    {
        $info = @getimagesize($origPath);
        if ($info === false) {
            throw new HttpException('The image could not be read.', 422, 'upload_image_invalid');
        }
        // Rough GD memory estimate (RGBA + overhead); better a clear error than a fatal error.
        $need = (int) ($info[0] * $info[1] * 5) + 32 * 1024 * 1024;
        $limit = $this->memoryLimitBytes();
        if ($limit > 0 && memory_get_usage() + $need > $limit) {
            throw new HttpException('The image is too large to process.', 422, 'upload_image_too_large');
        }

        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($origPath); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($origPath); break;
            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($origPath) : false; break;
            default: $src = false;
        }
        if (!$src) {
            throw new HttpException('The image could not be read.', 422, 'upload_image_invalid');
        }

        $meta = ['taken_at' => null, 'gps_lat' => null, 'gps_lon' => null];
        $orientation = 1;
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($origPath);
            if (is_array($exif)) {
                $orientation = (int) ($exif['Orientation'] ?? 1);
                $meta['taken_at'] = $this->exifDate($exif['DateTimeOriginal'] ?? ($exif['DateTime'] ?? null));
                $meta['gps_lat'] = $this->gpsToFloat($exif['GPSLatitude'] ?? null, $exif['GPSLatitudeRef'] ?? null);
                $meta['gps_lon'] = $this->gpsToFloat($exif['GPSLongitude'] ?? null, $exif['GPSLongitudeRef'] ?? null);
            }
        }
        $src = $this->orientGd($src, $orientation);
        $w = imagesx($src);
        $h = imagesy($src);
        $meta['width'] = $w;
        $meta['height'] = $h;

        foreach (self::VARIANTS as $suffix => [$max, $quality]) {
            $scale = min(1, $max / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imageinterlace($dst, 1);
            imagejpeg($dst, $base . '_' . $suffix . '.jpg', $quality);
            imagedestroy($dst);
        }
        imagedestroy($src);
        return $meta;
    }

    /** @param resource|\GdImage $img @return resource|\GdImage */
    private function orientGd($img, int $orientation)
    {
        switch ($orientation) {
            case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 3: $img = imagerotate($img, 180, 0); break;
            case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
            case 5: $img = imagerotate($img, -90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 6: $img = imagerotate($img, -90, 0); break;
            case 7: $img = imagerotate($img, 90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 8: $img = imagerotate($img, 90, 0); break;
        }
        return $img;
    }

    private function memoryLimitBytes(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': return $n * 1024 * 1024 * 1024;
            case 'm': return $n * 1024 * 1024;
            case 'k': return $n * 1024;
        }
        return $n;
    }

    // ------------------------------------------------------------ EXIF ---

    /** "2026:11:13 10:22:01" → "2026-11-13 10:22:01" (or null). */
    private function exifDate($raw): ?string
    {
        if (!is_string($raw) || !preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/', $raw, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }

    /**
     * GPS as EXIF rational numbers → degrees.
     * Input: array ["49/1","58/1","4512/100"] (exif_read_data) or string "49/1, 58/1, 4512/100" (Imagick).
     */
    private function gpsToFloat($dms, $ref): ?float
    {
        if (is_string($dms)) {
            $dms = array_map('trim', explode(',', $dms));
        }
        if (!is_array($dms) || count($dms) < 3) {
            return null;
        }
        $parts = [];
        foreach (array_slice(array_values($dms), 0, 3) as $r) {
            $r = (string) $r;
            if (strpos($r, '/') !== false) {
                [$a, $b] = explode('/', $r, 2);
                if ((float) $b == 0.0) {
                    return null;
                }
                $parts[] = (float) $a / (float) $b;
            } elseif (is_numeric($r)) {
                $parts[] = (float) $r;
            } else {
                return null;
            }
        }
        $deg = $parts[0] + $parts[1] / 60 + $parts[2] / 3600;
        if (in_array(strtoupper(trim((string) $ref)), ['S', 'W'], true)) {
            $deg = -$deg;
        }
        return ($deg === 0.0 || abs($deg) > 180) ? null : round($deg, 7);
    }
}

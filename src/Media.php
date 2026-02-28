<?php

declare(strict_types=1);

namespace SeeOurFamily;

use PDO;

/**
 * Media service: stores and serves documents (photos, videos, audio, PDFs, etc.)
 * outside the web root.
 *
 * Replaces the legacy layout where files lived under:
 *   /Gene/File/{FamilyName}/Image/   (images)
 *   /Gene/File/{FamilyName}/Document/ (documents)
 *
 * New layout (flat, UUID-based):
 *   {MEDIA_DIR}/{family_id}/{stored_filename}
 *   e.g. /var/media/seeourfamily/3/a3f8c2d1-xxxx.jpg
 *
 * Legacy files that have no stored_filename yet are resolved via the
 * old path convention using the family name.
 *
 * Files are served through /media/{uuid} which performs auth + family_id
 * checks before streaming bytes. Thumbnails are served via /media/{uuid}?tn=1.
 * Video/audio poster images are served via /media/{uuid}?poster=1.
 */
class Media
{
    private const VIDEO_EXT = ['mp4', 'avi', 'webm', 'mov', 'm4v'];
    private const AUDIO_EXT = ['mp3', 'ogg', 'wav'];
    private const IMAGE_EXT = ['jpg', 'jpeg', 'gif', 'png', 'webp'];

    private string $mediaDir;
    private ?string $legacyDir;

    public function __construct(
        private Database $db,
    ) {
        $this->mediaDir  = rtrim($_ENV['MEDIA_DIR'] ?? (__DIR__ . '/../media'), '/');

        // Legacy dir is only active when MEDIA_LEGACY_DIR is set and non-empty.
        // Once media migration is complete, set MEDIA_LEGACY_DIR= (empty) in .env
        // to disable legacy path resolution entirely.
        $legacy = $_ENV['MEDIA_LEGACY_DIR'] ?? '';
        $this->legacyDir = ($legacy !== '') ? rtrim($legacy, '/') : null;
    }

    /** Base media directory. */
    public function mediaDir(): string
    {
        return $this->mediaDir;
    }

    /** Check if a MIME type or extension represents video. */
    public static function isVideo(?string $mimeOrExt): bool
    {
        if ($mimeOrExt === null) return false;
        if (str_starts_with($mimeOrExt, 'video/')) return true;
        return in_array(strtolower($mimeOrExt), self::VIDEO_EXT, true);
    }

    /** Check if a MIME type or extension represents audio. */
    public static function isAudio(?string $mimeOrExt): bool
    {
        if ($mimeOrExt === null) return false;
        if (str_starts_with($mimeOrExt, 'audio/')) return true;
        return in_array(strtolower($mimeOrExt), self::AUDIO_EXT, true);
    }

    /** Check if a MIME type or extension represents an image. */
    public static function isImage(?string $mimeOrExt): bool
    {
        if ($mimeOrExt === null) return false;
        if (str_starts_with($mimeOrExt, 'image/')) return true;
        return in_array(strtolower($mimeOrExt), self::IMAGE_EXT, true);
    }

    /** Check if a MIME type represents a "visual" file (image, video, or audio) — the photo-grid types. */
    public static function isVisual(?string $mimeOrExt): bool
    {
        return self::isImage($mimeOrExt) || self::isVideo($mimeOrExt) || self::isAudio($mimeOrExt);
    }

    /** Detect MIME type from a document row (mime_type column, or fall back to extension). */
    public static function mimeFromRow(array $row): string
    {
        if (!empty($row['mime_type'])) return $row['mime_type'];
        $name = $row['stored_filename'] ?? $row['file_name'] ?? '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'png' => 'image/png', 'webp' => 'image/webp',
            'mp4' => 'video/mp4', 'avi' => 'video/x-msvideo', 'webm' => 'video/webm',
            'mov' => 'video/quicktime', 'm4v' => 'video/x-m4v',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * Resolve the absolute disk path for a document row.
     * Returns null if the file does not exist.
     */
    public function diskPath(array $row, string $familyName = ''): ?string
    {
        // New flat storage: stored_filename under {mediaDir}/{family_id}/
        if (!empty($row['stored_filename'])) {
            $path = $this->mediaDir . '/' . $row['family_id'] . '/' . $row['stored_filename'];
            if (file_exists($path)) {
                return $path;
            }
        }

        // Legacy path: /Gene/File/{FamilyName}/Image/{file_name}  (or /Document/)
        if ($this->legacyDir !== null && !empty($row['file_name']) && $familyName !== '') {
            $ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
            $isImage = in_array($ext, ['jpg', 'jpeg', 'gif', 'png', 'webp'], true);
            $subDir = $isImage ? 'Image' : 'Document';
            $path = $this->legacyDir . '/Gene/File/' . $familyName . '/' . $subDir . '/' . $row['file_name'];
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Resolve the thumbnail disk path for an image row.
     * Legacy thumbnails use the .tn.{ext} naming convention.
     * New thumbnails are stored as {uuid}.tn.{ext} in flat storage.
     */
    public function thumbnailPath(array $row, string $familyName = ''): ?string
    {
        // New flat storage thumbnail
        if (!empty($row['stored_filename'])) {
            $tnName = preg_replace('/\.(\w+)$/', '.tn.$1', $row['stored_filename']);
            $path = $this->mediaDir . '/' . $row['family_id'] . '/' . $tnName;
            if (file_exists($path)) {
                return $path;
            }
        }

        // Legacy thumbnail
        if ($this->legacyDir !== null && !empty($row['file_name']) && $familyName !== '') {
            $tnName = preg_replace('/\.(\w+)$/', '.tn.$1', $row['file_name']);
            $path = $this->legacyDir . '/Gene/File/' . $familyName . '/Image/' . $tnName;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Store an uploaded file using flat UUID-based naming.
     *
     * @return array{stored_filename: string, original_filename: string, mime_type: string, file_size: int}|null
     */
    public function storeUpload(array $file, int $familyId): ?array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        // Validate extension
        $allowedExt = [
            'jpg', 'jpeg', 'gif', 'png', 'webp',
            'mp3', 'ogg', 'wav',
            'mp4', 'avi', 'webm', 'mov', 'm4v',
            'pdf', 'txt', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
        ];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return null;
        }

        // Validate MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMime = [
            'image/jpeg', 'image/gif', 'image/png', 'image/webp',
            'audio/mpeg', 'audio/ogg', 'audio/wav',
            'video/mp4', 'video/x-msvideo', 'video/avi', 'video/webm',
            'video/quicktime', 'video/x-m4v',
            'application/pdf',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // Office files often detected as generic zip
            'application/zip',
            'application/octet-stream',
        ];
        if (!in_array($mime, $allowedMime, true)) {
            return null;
        }

        // Generate UUID-based filename
        $storedName = $this->generateUuid() . '.' . $ext;
        $dir = $this->mediaDir . '/' . $familyId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $target = $dir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return null;
        }

        // Generate thumbnail for images
        if (in_array($ext, ['jpg', 'jpeg', 'gif', 'png'], true)) {
            $this->createThumbnail($target, $dir, $storedName);
        }

        return [
            'stored_filename'  => $storedName,
            'original_filename' => basename($file['name']),
            'mime_type'         => $mime,
            'file_size'         => (int)filesize($target),
        ];
    }

    /**
     * Create a 100px max-dimension thumbnail alongside the source.
     */
    public function createThumbnail(string $srcPath, string $dir, string $storedName): void
    {
        $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
        $tnName = preg_replace('/\.(\w+)$/', '.tn.$1', $storedName);
        $tnPath = $dir . '/' . $tnName;

        $src = null;
        if ($ext === 'jpg' || $ext === 'jpeg') $src = @imagecreatefromjpeg($srcPath);
        elseif ($ext === 'png')                $src = @imagecreatefrompng($srcPath);
        elseif ($ext === 'gif')                $src = @imagecreatefromgif($srcPath);
        if (!$src) return;

        $w = imagesx($src);
        $h = imagesy($src);
        $max = 100;
        if ($w >= $h) { $nw = min($w, $max); $nh = (int)round($h * $nw / $w); }
        else          { $nh = min($h, $max); $nw = (int)round($w * $nh / $h); }

        $tn = imagecreatetruecolor(max($nw, 1), max($nh, 1));
        if ($ext === 'png' || $ext === 'gif') {
            imagealphablending($tn, false);
            imagesavealpha($tn, true);
            imagefilledrectangle($tn, 0, 0, $nw, $nh, imagecolorallocatealpha($tn, 0, 0, 0, 127));
        }
        imagecopyresampled($tn, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if ($ext === 'jpg' || $ext === 'jpeg') imagejpeg($tn, $tnPath, 85);
        elseif ($ext === 'png')                imagepng($tn, $tnPath);
        elseif ($ext === 'gif')                imagegif($tn, $tnPath);

        imagedestroy($src);
        imagedestroy($tn);
    }

    /**
     * Serve a media file by UUID. Sends HTTP headers and streams bytes.
     * Returns false if not found / not authorized.
     *
     * Options: thumbnail=true serves .tn thumbnail, poster=true serves
     * the poster image (looked up via poster_uuid) for video/audio files.
     */
    public function serve(string $uuid, ?int $familyId, string $familyName = '', bool $thumbnail = false, bool $poster = false): bool
    {
        $pdo = $this->db->pdo();
        $sql = 'SELECT * FROM documents WHERE uuid = ?';
        $params = [$uuid];
        if ($familyId !== null) {
            $sql .= ' AND family_id = ?';
            $params[] = $familyId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        // Poster request: redirect to serving the poster image
        if ($poster && !empty($row['poster_uuid'])) {
            return $this->serve($row['poster_uuid'], $familyId, $familyName, $thumbnail);
        }

        // Resolve path
        $path = $thumbnail
            ? ($this->thumbnailPath($row, $familyName) ?? $this->diskPath($row, $familyName))
            : $this->diskPath($row, $familyName);

        if ($path === null || !file_exists($path)) {
            return false;
        }

        // Determine MIME type
        $mime = $row['mime_type'] ?: self::mimeFromRow($row);

        $size = filesize($path);
        header('Cache-Control: private, max-age=86400');

        // Range request support for video/audio streaming
        if (self::isVideo($mime) || self::isAudio($mime)) {
            header('Accept-Ranges: bytes');
            if (isset($_SERVER['HTTP_RANGE'])) {
                $range = $_SERVER['HTTP_RANGE'];
                if (preg_match('/bytes=(\d+)-(\d*)/', $range, $m)) {
                    $start = (int)$m[1];
                    $end = ($m[2] !== '') ? (int)$m[2] : $size - 1;
                    $end = min($end, $size - 1);
                    $length = $end - $start + 1;
                    http_response_code(206);
                    header('Content-Type: ' . $mime);
                    header("Content-Range: bytes $start-$end/$size");
                    header('Content-Length: ' . $length);
                    $fp = fopen($path, 'rb');
                    fseek($fp, $start);
                    echo fread($fp, $length);
                    fclose($fp);
                    return true;
                }
            }
        }

        // Full file serving
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        readfile($path);
        return true;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

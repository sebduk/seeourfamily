<?php

declare(strict_types=1);

namespace SeeOurFamily;

use PDO;

/**
 * Media service: stores and serves photos/documents outside the web root.
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
 */
class Media
{
    private string $mediaDir;
    private string $legacyDir;

    public function __construct(
        private Database $db,
    ) {
        $this->mediaDir  = rtrim($_ENV['MEDIA_DIR'] ?? (__DIR__ . '/../media'), '/');
        $this->legacyDir = rtrim($_ENV['MEDIA_LEGACY_DIR'] ?? ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/..'), '/');
    }

    /** Base media directory. */
    public function mediaDir(): string
    {
        return $this->mediaDir;
    }

    /**
     * Resolve the absolute disk path for a photo/document row.
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
        if (!empty($row['file_name']) && $familyName !== '') {
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
        if (!empty($row['file_name']) && $familyName !== '') {
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
        $allowedExt = ['jpg', 'jpeg', 'gif', 'png', 'mp3', 'mp4', 'avi', 'pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return null;
        }

        // Validate MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMime = [
            'image/jpeg', 'image/gif', 'image/png',
            'audio/mpeg', 'video/mp4', 'video/x-msvideo', 'video/avi',
            'application/pdf',
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
     */
    public function serve(string $uuid, ?int $familyId, string $familyName = '', bool $thumbnail = false): bool
    {
        $pdo = $this->db->pdo();
        $sql = 'SELECT * FROM photos WHERE uuid = ?';
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

        // Resolve path
        $path = $thumbnail
            ? ($this->thumbnailPath($row, $familyName) ?? $this->diskPath($row, $familyName))
            : $this->diskPath($row, $familyName);

        if ($path === null || !file_exists($path)) {
            return false;
        }

        // Determine MIME type
        $mime = $row['mime_type'];
        if (!$mime) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);
        }

        // Cache headers (media doesn't change often)
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');

        // Stream the file
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

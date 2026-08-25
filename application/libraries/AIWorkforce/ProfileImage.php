<?php
namespace AIWorkforce;

/**
 * Secure profile-image storage. Validates content (not the client filename),
 * writes a random name under /assets/uploads/avatars, and never trusts
 * executable uploads.
 */
final class ProfileImage
{
    public const MAX_BYTES = 2097152; // 2 MB
    public const PUBLIC_DIR = '/assets/uploads/avatars';

    /** @var array<int,string> */
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** @var array<string,string> */
    private const MIMES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public static function directory(): string
    {
        return rtrim(FCPATH, '/\\') . str_replace('/', DIRECTORY_SEPARATOR, self::PUBLIC_DIR);
    }

    /** Create the public upload directory and deny PHP execution inside it. */
    public static function prepareDirectory(): ?string
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return 'Profile storage is not available. Please try again later.';
        }
        $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(?i:php|phtml|php\\d|phar|cgi|pl|exe|shtml)$\">\n  Require all denied\n</FilesMatch>\nphp_flag engine off\n");
        }
        $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($idx)) @file_put_contents($idx, '');
        return null;
    }

    /**
     * Validate and persist an uploaded image.
     * @param array<string,mixed> $file one $_FILES entry
     * @return array{ok:bool,path?:string,error?:string}
     */
    public static function store(array $file, int $userId): array
    {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Choose an image to upload.'];
        }
        if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return ['ok' => false, 'error' => 'The profile picture could not be uploaded. Please use a JPG, PNG or WebP image under the allowed file size.'];
        }
        if ($code !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The profile picture could not be uploaded. Please try again.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || $size <= 0) {
            return ['ok' => false, 'error' => 'Choose an image to upload.'];
        }
        if ($size > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'The profile picture could not be uploaded. Please use a JPG, PNG or WebP image under the allowed file size.'];
        }

        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::TYPES[$info[2] ?? 0])) {
            return ['ok' => false, 'error' => 'The profile picture could not be uploaded. Please use a JPG, PNG or WebP image under the allowed file size.'];
        }
        $ext = self::TYPES[$info[2]];
        $expectedMime = self::MIMES[$ext];

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
            if ($finfo) finfo_close($finfo);
            if ($mime !== '' && $mime !== $expectedMime) {
                return ['ok' => false, 'error' => 'The profile picture could not be uploaded. Please use a JPG, PNG or WebP image under the allowed file size.'];
            }
        }

        $dirError = self::prepareDirectory();
        if ($dirError !== null) return ['ok' => false, 'error' => $dirError];

        $filename = 'u' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = self::directory() . DIRECTORY_SEPARATOR . $filename;
        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$moved || !is_file($dest) || filesize($dest) <= 0) {
            @unlink($dest);
            return ['ok' => false, 'error' => 'The profile picture could not be uploaded. Please try again.'];
        }
        @chmod($dest, 0644);
        return ['ok' => true, 'path' => self::PUBLIC_DIR . '/' . $filename];
    }

    public static function deletePublicPath(?string $path): void
    {
        $path = (string) $path;
        if ($path === '' || !str_starts_with($path, self::PUBLIC_DIR . '/')) return;
        $base = basename($path);
        if ($base === '' || $base === '.' || $base === '..' || str_contains($base, '/') || str_contains($base, '\\')) return;
        $full = self::directory() . DIRECTORY_SEPARATOR . $base;
        if (is_file($full)) @unlink($full);
    }
}

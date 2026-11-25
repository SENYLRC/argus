<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Config;

/**
 * Upload validation + storage (build-notes §6): whitelist by finfo (never
 * trust the client-sent MIME or the filename extension alone), size caps,
 * everything written outside the web root. Signature images are additionally
 * re-encoded with GD to strip EXIF and any embedded payload — "a leaked
 * signature PNG is a forgery kit".
 */
final class UploadService
{
    /**
     * Validate (real content type via finfo, size cap) and store an invoice/receipt outside the web root.
     * Returns the stored-file details for RequestRepository::addAttachment().
     */
    public static function storeAttachment(array $file): array
    {
        self::assertUploadOk($file);
        $maxBytes = (int) Config::get('uploads.attachment_max_bytes');
        $allowed = Config::get('uploads.attachment_mimes', []);
        return self::storeValidated($file, $maxBytes, $allowed, self::attachmentDir(), keepOriginalBytes: true);
    }

    /**
     * Validate and re-encode a signature image through GD (strips EXIF/payloads; min 600px wide), store it
     * outside the web root and return its path relative to storage/.
     */
    public static function storeSignature(array $file): string
    {
        self::assertUploadOk($file);
        $maxBytes = (int) Config::get('uploads.signature_max_bytes');
        $allowed = Config::get('uploads.signature_mimes', []);

        $dims = @getimagesize($file['tmp_name']);
        if ($dims === false || $dims[0] < 600) {
            throw new \InvalidArgumentException('Signature image must be at least 600px wide.');
        }
        // Refuse "decompression bombs" (tiny file, huge canvas) before GD
        // allocates the full bitmap.
        if ($dims[0] * $dims[1] > 25_000_000) {
            throw new \InvalidArgumentException('Signature image is too large — keep it under about 5000 × 5000 pixels.');
        }

        $mime = self::detectMime($file['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            throw new \InvalidArgumentException('Signature must be a PNG or JPG image.');
        }
        if ($file['size'] > $maxBytes) {
            throw new \InvalidArgumentException('Signature image is larger than the 2MB limit.');
        }

        // Re-encode with GD: this both strips EXIF/any embedded payload and
        // normalizes everything to PNG regardless of the source format.
        $image = match ($mime) {
            'image/png'  => imagecreatefrompng($file['tmp_name']),
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            default      => throw new \InvalidArgumentException('Unsupported image type.'),
        };
        if ($image === false) {
            throw new \InvalidArgumentException('Could not read the uploaded image.');
        }
        imagesavealpha($image, true);

        $dir = self::signatureDir();
        self::ensureDir($dir);
        $filename = bin2hex(random_bytes(16)) . '.png';
        $fullPath = $dir . '/' . $filename;
        imagepng($image, $fullPath);
        imagedestroy($image);

        return self::relativeToStorage($fullPath);
    }

    /**
     * Copy the chosen signature to an immutable per-signoff path (build-notes
     * §2c) so replacing or retiring the source image never changes what an
     * already-signed record prints.
     */
    public static function copySignatureForSignoff(string $storedRelativePath): string
    {
        $source = self::storageRoot() . '/' . $storedRelativePath;
        $dir = self::storageRoot() . '/' . Config::get('storage.signatures') . '/signoffs';
        self::ensureDir($dir);
        $dest = $dir . '/' . bin2hex(random_bytes(16)) . '.png';
        if (!@copy($source, $dest)) {
            throw new \RuntimeException('Could not copy signature image for this sign-off.');
        }
        return self::relativeToStorage($dest);
    }

    private static function storeValidated(array $file, int $maxBytes, array $allowedMimes, string $dir, bool $keepOriginalBytes): array
    {
        if ($file['size'] > $maxBytes) {
            throw new \InvalidArgumentException('File is larger than the allowed limit.');
        }
        $mime = self::detectMime($file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true)) {
            throw new \InvalidArgumentException('Unsupported file type — attach a PDF, JPG or PNG.');
        }

        self::ensureDir($dir);
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            default            => 'bin',
        };
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $fullPath = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            throw new \RuntimeException('Could not save the uploaded file.');
        }

        return [
            'orig_name'   => basename($file['name']),
            'stored_path' => self::relativeToStorage($fullPath),
            'mime'        => $mime,
            'bytes'       => (int) ($keepOriginalBytes ? $file['size'] : filesize($fullPath)),
        ];
    }

    private static function assertUploadOk(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Upload failed.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Invalid upload.');
        }
    }

    private static function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime ?: 'application/octet-stream';
    }

    private static function attachmentDir(): string
    {
        return self::storageRoot() . '/' . Config::get('storage.attachments') . '/' . date('Y/m');
    }

    private static function signatureDir(): string
    {
        return self::storageRoot() . '/' . Config::get('storage.signatures');
    }

    private static function storageRoot(): string
    {
        return rtrim((string) Config::get('storage.root'), '/');
    }

    private static function relativeToStorage(string $fullPath): string
    {
        return ltrim(substr($fullPath, strlen(self::storageRoot())), '/');
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create storage directory: {$dir}");
        }
    }
}

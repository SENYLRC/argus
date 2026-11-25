<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Logos used across the site, PDFs and emails (Administration > Files &
 * branding). Each slot defaults to the official Southeastern file shipped in
 * public/assets/brand/; an admin can upload a replacement, which is stored
 * under storage/branding/ (writable by the web server, outside the web root)
 * and served by public/brand.php.
 *
 * Uploads are re-encoded through GD to PNG — same as signature images — so
 * nothing but pixels survives (no EXIF, no embedded payload). SVG uploads are
 * refused for the same reason: an SVG can carry script.
 */
final class Branding
{
    public const SLOTS = [
        'header' => [
            'label'   => 'Header & email logo',
            'help'    => 'White horizontal logo, shown on the navy header bar of every page and at the top of every email.',
            'default' => 'assets/brand/argus-logo-horizontal-white.png',
            'preview_bg' => '#1C4C7C',
        ],
        'login' => [
            'label'   => 'Sign-in page logo',
            'help'    => 'Two-colour logo on the sign-in and set-password pages.',
            'default' => 'assets/brand/argus-logo-stacked-color.svg',
            'preview_bg' => '#FFFFFF',
        ],
        'pdf' => [
            'label'   => 'PDF cover sheet logo',
            'help'    => 'Two-colour horizontal logo printed on each request’s cover sheet.',
            'default' => 'assets/brand/argus-logo-horizontal-color.png',
            'preview_bg' => '#FFFFFF',
        ],
    ];

    private const MAX_BYTES = 2 * 1024 * 1024;

    /** True if an admin has uploaded a custom logo for this slot (rather than the built-in placeholder). */
    public static function isCustom(string $slot): bool
    {
        return self::customPath($slot) !== null;
    }

    /** Browser URL for the slot's current logo. */
    public static function url(string $slot): string
    {
        $custom = self::customPath($slot);
        if ($custom !== null) {
            return '/brand.php?slot=' . rawurlencode($slot) . '&v=' . filemtime($custom);
        }
        return '/' . (self::SLOTS[$slot]['default'] ?? self::SLOTS['header']['default']);
    }

    /** Filesystem path of the slot's current logo (for PDFs and email embedding). */
    public static function path(string $slot): string
    {
        return self::customPath($slot)
            ?? dirname(__DIR__, 2) . '/public/' . (self::SLOTS[$slot]['default'] ?? self::SLOTS['header']['default']);
    }

    /** Stored custom file for a slot, or null (none set, or file missing). */
    public static function customPath(string $slot): ?string
    {
        if (!isset(self::SLOTS[$slot])) {
            return null;
        }
        $rel = Settings::get('brand_' . $slot);
        if ($rel === '' || !str_starts_with($rel, 'branding/') || str_contains($rel, '..')) {
            return null;
        }
        $full = self::storageRoot() . '/' . $rel;
        return is_file($full) ? $full : null;
    }

    /** Validate, re-encode and store an uploaded replacement logo. */
    public static function store(string $slot, array $file): void
    {
        if (!isset(self::SLOTS[$slot])) {
            throw new \InvalidArgumentException('Unknown logo slot.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Choose a PNG or JPG file to upload.');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Logo files must be 2MB or smaller.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
        finfo_close($finfo);

        // Refuse "decompression bombs" (tiny file, huge canvas) before GD
        // allocates the full bitmap.
        $dims = @getimagesize($file['tmp_name']);
        if ($dims === false || $dims[0] * $dims[1] > 25_000_000) {
            throw new \InvalidArgumentException('That image is unreadable or too large — keep logos under about 5000 × 5000 pixels.');
        }
        $image = match ($mime) {
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            default      => throw new \InvalidArgumentException('Logos must be PNG or JPG (SVG isn’t accepted — it can carry script).'),
        };
        if ($image === false) {
            throw new \InvalidArgumentException('Could not read that image.');
        }
        if (imagesy($image) < 64) {
            imagedestroy($image);
            throw new \InvalidArgumentException('Logo must be at least 64px tall so its text stays legible.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $dir = self::storageRoot() . '/branding';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            imagedestroy($image);
            throw new \RuntimeException('Could not create storage/branding.');
        }
        $rel = 'branding/' . $slot . '-' . bin2hex(random_bytes(8)) . '.png';
        imagepng($image, self::storageRoot() . '/' . $rel);
        imagedestroy($image);

        $old = self::customPath($slot);
        Settings::set('brand_' . $slot, $rel);
        if ($old !== null) {
            @unlink($old);
        }
    }

    /** Go back to the official logo. */
    public static function reset(string $slot): void
    {
        $old = self::customPath($slot);
        Settings::set('brand_' . $slot, '');
        if ($old !== null) {
            @unlink($old);
        }
    }

    private static function storageRoot(): string
    {
        return rtrim((string) Config::get('storage.root'), '/');
    }
}

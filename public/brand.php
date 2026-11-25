<?php

declare(strict_types=1);

/**
 * Serves an admin-uploaded replacement logo from storage/branding/ (outside
 * the web root). No sign-in needed — logos appear on the sign-in page. Only
 * whitelisted slots, and only files Branding itself wrote (always PNG).
 */

use App\Support\Branding;

require __DIR__ . '/../bootstrap.php';

$path = Branding::customPath((string) ($_GET['slot'] ?? ''));
if ($path === null) {
    http_response_code(404);
    echo 'Not found.';
    return;
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
// URLs carry ?v=<mtime>, so a replaced logo gets a new URL — safe to cache hard.
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);

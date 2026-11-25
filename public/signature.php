<?php

declare(strict_types=1);

/**
 * Authenticated signature-image download (build-notes §2c/§6: "served only
 * through an authenticated handler — a leaked signature PNG is a forgery
 * kit"). Only the signature's own owner or an admin may view it; nobody else
 * needs the raw file — the sign-off UI shows the typed name as the preview,
 * not the image itself (see templates/partials/_signature_picker.twig).
 */

use App\Support\Auth;
use App\Support\Config;
use App\Support\Db;
use App\Support\Session;

require __DIR__ . '/../bootstrap.php';
Session::start();

if (!Auth::check()) {
    http_response_code(401);
    echo 'Sign in required.';
    return;
}
// Required two-step sign-in not set up yet: no files until it is.
if (\App\Domain\TwoFactorService::setupRequired(Auth::user())) {
    http_response_code(403);
    echo 'Set up two-step sign-in on My profile first.';
    return;
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = Db::conn()->prepare('SELECT * FROM signature_images WHERE id = :id');
$stmt->execute(['id' => $id]);
$signature = $stmt->fetch();

if (!$signature) {
    http_response_code(404);
    echo 'Not found.';
    return;
}

if (!Auth::isAdmin() && (int) $signature['user_id'] !== (int) Auth::id()) {
    http_response_code(403);
    echo 'Not authorized.';
    return;
}

$path = rtrim((string) Config::get('storage.root'), '/') . '/' . $signature['stored_path'];
if (!is_file($path)) {
    http_response_code(404);
    echo 'File is missing from storage.';
    return;
}

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="signature.png"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);

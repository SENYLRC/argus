<?php

declare(strict_types=1);

/**
 * Authenticated attachment download. Files live under storage/attachments/
 * outside the web root (build-notes §6) — this is the only way to fetch one.
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
$stmt = Db::conn()->prepare(
    'SELECT a.*, r.requester_id FROM attachments a JOIN requests r ON r.id = a.request_id WHERE a.id = :id'
);
$stmt->execute(['id' => $id]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    echo 'Not found.';
    return;
}

if (Auth::isStaff() && (int) $attachment['requester_id'] !== (int) Auth::id()) {
    http_response_code(403);
    echo 'Not authorized.';
    return;
}

$path = rtrim((string) Config::get('storage.root'), '/') . '/' . $attachment['stored_path'];
if (!is_file($path)) {
    http_response_code(404);
    echo 'File is missing from storage.';
    return;
}

\App\Domain\AuditLogger::log('request', (int) $attachment['request_id'], 'view_attachment', "Opened attachment {$attachment['orig_name']}");

// The original filename is client-supplied at upload time — strip anything
// that could break out of the quoted Content-Disposition parameter (a stray
// `"` could otherwise inject extra parameters into the header value).
$safeName = str_replace('"', "'", basename($attachment['orig_name']));

header('Content-Type: ' . $attachment['mime']);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\SignatureRepository;
use App\Domain\UploadService;
use App\Http\Controller;
use App\Support\Auth;

/**
 * Signature uploads (build-notes §2c): a fresh file, validated and
 * re-encoded by UploadService, becomes a new option in the sign-off tile
 * list — it's a separate action from the sign-off form itself so a failed
 * or successful upload never loses what was already typed into that form.
 */
final class SignatureController extends Controller
{
    /** Add a signature image for the signed-in approver (validated and re-encoded). */
    public function upload(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $returnTo = $this->safeReturnTo((string) ($_POST['return_to'] ?? '/requests'));

        if (empty($_FILES['signature']['name'])) {
            $this->flash('Choose a signature image to upload.');
            $this->redirect($returnTo);
            return;
        }

        try {
            $path = UploadService::storeSignature($_FILES['signature']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->flash($e->getMessage());
            $this->redirect($returnTo);
            return;
        }

        $label = trim((string) ($_POST['label'] ?? '')) ?: 'Signature';
        $makeDefault = !empty($_POST['make_default']) || count(SignatureRepository::forUser((int) $user['id'])) === 0;
        $id = SignatureRepository::add((int) $user['id'], $label, $path, $makeDefault);

        AuditLogger::log('user', (int) $user['id'], 'upload_signature', 'Uploaded signature image ' . basename($path));
        $this->flash('Signature uploaded' . ($makeDefault ? ' and set as default.' : '.') . ' Select it below to sign.');
        $this->redirect($returnTo);
    }

    /** Make one of the person's own images their default. */
    public function setDefault(array $params): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $returnTo = $this->safeReturnTo((string) ($_POST['return_to'] ?? '/requests'));
        $id = (int) $params['id'];

        $signature = SignatureRepository::find($id);
        if (!$signature || (int) $signature['user_id'] !== (int) $user['id']) {
            $this->flash('That signature is not on your account.');
            $this->redirect($returnTo);
            return;
        }

        SignatureRepository::setDefault((int) $user['id'], $id);
        \App\Domain\AuditLogger::log('user', (int) $user['id'], 'signature_default', "Set default signature: {$signature['label']}");
        $this->flash('Default signature updated — it will be pre-selected next time.');
        $this->redirect($returnTo);
    }

    private function safeReturnTo(string $path): string
    {
        return $this->isSafeLocalPath($path) ? $path : '/requests';
    }
}

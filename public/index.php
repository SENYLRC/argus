<?php

declare(strict_types=1);

/**
 * Web front controller: Apache sends every request that isn't a real file
 * here (see deploy/vhost.conf). It wires the controllers, registers the route
 * table (GET/POST path => controller method) and dispatches the request.
 * attachment.php, signature.php and brand.php are separate entry points
 * because they stream files rather than render Twig pages.
 */

use App\Domain\MailService;
use App\Domain\PdfService;
use App\Domain\RequestWorkflow;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminAuditController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminEmailController;
use App\Http\Controllers\AdminFilesController;
use App\Http\Controllers\AdminIntegrityController;
use App\Http\Controllers\AdminProtectionController;
use App\Http\Controllers\AdminQuickBooksController;
use App\Http\Controllers\AdminSecurityController;
use App\Http\Controllers\AdminSiteController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\EmailsController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\RequestDetailController;
use App\Http\Controllers\RequestFormController;
use App\Http\Controllers\RequestListController;
use App\Http\Controllers\SignatureController;
use App\Http\Controllers\UsersController;
use App\Http\Router;
use App\Support\Config;
use App\Support\Session;

/** @var \Twig\Environment $twig */
$twig = require __DIR__ . '/../bootstrap.php';

if (Config::isDevelopment()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    // Belt-and-braces: this app doesn't rely on php.ini alone for this — an
    // uncaught exception must never echo a stack trace (file paths, SQL,
    // possibly a signed-in user's data) straight to the browser.
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    set_exception_handler(function (\Throwable $e): void {
        error_log('[unhandled] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'Something went wrong on our end. Try again, and let Admin / Tech know if it keeps happening.';
    });
}

Session::start();

// Refuse locked-out and blocked addresses before anything else runs.
\App\Support\IpGuard::enforce($_SERVER['REQUEST_URI'] ?? '/');

$mail = new MailService($twig);
$workflow = new RequestWorkflow($mail);
$pdf = new PdfService($twig);

$auth = new AuthController($twig, $mail);
$account = new AccountController($twig, $mail);
$list = new RequestListController($twig);
$form = new RequestFormController($twig);
$detail = new RequestDetailController($twig);
$approval = new ApprovalController($twig, $workflow);
$batch = new BatchController($twig);
$pdfCtrl = new PdfController($twig, $pdf);
$users = new UsersController($twig, $mail);
$admin = new AdminController($twig);
$adminEmail = new AdminEmailController($twig, $mail);
$adminFiles = new AdminFilesController($twig);
$adminQbo = new AdminQuickBooksController($twig);
$adminSecurity = new AdminSecurityController($twig, $mail);
$adminSite = new AdminSiteController($twig);
$adminProtection = new AdminProtectionController($twig);
$adminIntegrity = new AdminIntegrityController($twig, $mail);
$adminAudit = new AdminAuditController($twig);
$emails = new EmailsController($twig);
$signatures = new SignatureController($twig);

$router = new Router();

$router->get('/', function () {
    header('Location: /requests');
});
$router->get('/login', fn () => $auth->showLogin());
$router->post('/login', fn () => $auth->login());
$router->get('/login/verify', fn () => $auth->showVerify());
$router->post('/login/verify', fn () => $auth->verify());
$router->post('/login/verify/key/options', fn () => $auth->securityKeyOptions());
$router->post('/login/verify/key', fn () => $auth->securityKeyLogin());
$router->post('/logout', fn () => $auth->logout());
$router->get('/account', fn () => $account->index());
$router->post('/account/profile', fn () => $account->saveProfile());
$router->post('/account/email', fn () => $account->requestEmailChange());
$router->post('/account/email/cancel', fn () => $account->cancelEmailChange());
$router->get('/account/email/confirm/{token}', fn ($p) => $account->showConfirmEmail($p));
$router->post('/account/email/confirm/{token}', fn ($p) => $account->confirmEmail($p));
$router->get('/profile', fn () => $account->index());
$router->post('/account/password', fn () => $account->changePassword());
$router->post('/account/2fa/start', fn () => $account->startTwoFactor());
$router->post('/account/2fa/enable', fn () => $account->enableTwoFactor());
$router->post('/account/2fa/disable', fn () => $account->disableTwoFactor());
$router->post('/account/2fa/recovery-codes', fn () => $account->regenerateCodes());
$router->post('/account/2fa/app/remove', fn () => $account->removeApp());
$router->post('/account/keys/options', fn () => $account->securityKeyOptions());
$router->post('/account/keys', fn () => $account->registerSecurityKey());
$router->post('/account/keys/{id}/remove', fn ($p) => $account->removeSecurityKey($p));
$router->get('/set-password/{token}', fn ($p) => $auth->showSetPassword($p));
$router->post('/set-password/{token}', fn ($p) => $auth->setPassword($p));
$router->post('/flash/dismiss', fn () => $auth->dismissFlash());
$router->get('/accessibility', fn () => $admin->accessibility());

$router->get('/requests', fn () => $list->index());
$router->get('/requests/new', fn () => $form->showNew());
$router->post('/requests', fn () => $form->create());
$router->get('/requests/{id}', fn ($p) => $detail->show($p));
$router->get('/requests/{id}/edit', fn ($p) => $form->showEdit($p));
$router->post('/requests/{id}', fn ($p) => $form->update($p));
$router->post('/requests/{id}/delete', fn ($p) => $form->delete($p));
$router->post('/requests/{id}/approve', fn ($p) => $approval->approve($p));
$router->post('/requests/{id}/reject', fn ($p) => $approval->reject($p));
$router->get('/requests/{id}/record', fn ($p) => $pdfCtrl->record($p));
$router->get('/requests/{id}/record.pdf', fn ($p) => $pdfCtrl->recordPdf($p));

$router->post('/records/batch/preview', fn () => $pdfCtrl->batchPreview());
$router->post('/records/batch.pdf', fn () => $pdfCtrl->batchPdf());

$router->get('/batch', fn () => $batch->show());
$router->post('/batch', fn () => $batch->submit());
$router->post('/batch/lines', fn () => $batch->saveLine([]));
$router->post('/batch/lines/{id}', fn ($p) => $batch->saveLine($p));
$router->post('/batch/lines/{id}/remove', fn ($p) => $batch->removeLine($p));

$router->get('/users', fn () => $users->index());
$router->get('/users/new', fn () => $users->showNew());
$router->get('/users/{id}/edit', fn ($p) => $users->showEdit($p));
$router->post('/users', fn () => $users->create());
$router->post('/users/{id}', fn ($p) => $users->update($p));
$router->post('/users/{id}/reset', fn ($p) => $users->reset($p));
$router->post('/users/{id}/toggle', fn ($p) => $users->toggle($p));
$router->post('/users/{id}/delete', fn ($p) => $users->delete($p));

$router->get('/admin', fn () => $admin->index());
$router->post('/admin/budget-lines', fn () => $admin->addBudgetLine());
$router->post('/admin/budget-lines/{id}/promote', fn ($p) => $admin->promoteBudgetLine($p));
$router->post('/admin/budget-lines/{id}/access', fn ($p) => $admin->saveBudgetLineAccess($p));
$router->post('/admin/budget-lines/{id}/remove', fn ($p) => $admin->removeBudgetLine($p));
$router->post('/admin/budget-lines/{id}/budget', fn ($p) => $admin->saveBudgetLineBudget($p));
$router->post('/admin/budget-lines/{id}/restore', fn ($p) => $admin->restoreBudgetLine($p));
$router->get('/admin/email', fn () => $adminEmail->index());
$router->post('/admin/email-mode', fn () => $adminEmail->saveMode());
$router->post('/admin/email/options', fn () => $adminEmail->saveOptions());
$router->post('/admin/email/test', fn () => $adminEmail->sendTest());
$router->get('/admin/files', fn () => $adminFiles->index());
$router->post('/admin/files/logo/{slot}', fn ($p) => $adminFiles->uploadLogo($p));
$router->post('/admin/files/logo/{slot}/reset', fn ($p) => $adminFiles->resetLogo($p));
$router->post('/admin/files/attachments/{id}/delete', fn ($p) => $adminFiles->deleteAttachment($p));
$router->post('/admin/files/orphans/delete', fn () => $adminFiles->deleteOrphans());
$router->post('/admin/files/cache/clear', fn () => $adminFiles->clearCache());
$router->get('/admin/audit', fn () => $adminAudit->index());
$router->get('/admin/audit/export', fn () => $adminAudit->export());
$router->get('/admin/quickbooks', fn () => $adminQbo->index());
$router->get('/admin/quickbooks/callback', fn () => $adminQbo->callback());
$router->post('/admin/quickbooks/connect', fn () => $adminQbo->connect());
$router->post('/admin/quickbooks/disconnect', fn () => $adminQbo->disconnect());
$router->post('/admin/quickbooks/settings', fn () => $adminQbo->saveSettings());
$router->post('/admin/quickbooks/preview', fn () => $adminQbo->preview());
$router->post('/admin/quickbooks/apply', fn () => $adminQbo->apply());
$router->get('/admin/security', fn () => $adminSecurity->index());
$router->post('/admin/security', fn () => $adminSecurity->save());
$router->post('/admin/security/reset/{id}', fn ($p) => $adminSecurity->resetOne($p));
$router->post('/admin/security/force-passwords', fn () => $adminSecurity->forcePasswords());
$router->post('/admin/security/reset-all', fn () => $adminSecurity->resetAll());
$router->get('/admin/protection', fn () => $adminProtection->index());
$router->post('/admin/protection', fn () => $adminProtection->save());
$router->post('/admin/protection/unlock', fn () => $adminProtection->unlock());
$router->get('/admin/integrity', fn () => $adminIntegrity->index());
$router->post('/admin/integrity/scan', fn () => $adminIntegrity->scan());
$router->post('/admin/integrity/accept', fn () => $adminIntegrity->accept());
$router->post('/admin/integrity/settings', fn () => $adminIntegrity->save());
$router->get('/admin/site', fn () => $adminSite->index());
$router->post('/admin/site', fn () => $adminSite->save());

$router->get('/emails', fn () => $emails->index());

$router->post('/signatures', fn () => $signatures->upload());
$router->post('/signatures/{id}/default', fn ($p) => $signatures->setDefault($p));

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

// Scanner lockout: count pages that don't exist (visitors only; IpGuard skips signed-in people).
if (http_response_code() === 404) {
    \App\Support\IpGuard::record404();
}

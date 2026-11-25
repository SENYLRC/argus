<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\EmailChangeService;
use App\Domain\SecurityKeyService;
use App\Domain\MailService;
use App\Domain\TwoFactorService;
use App\Domain\UserRepository;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Totp;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Twig\Environment;

/**
 * My profile (every signed-in person): name and job title, sign-in email
 * (confirmed from a link sent to the new address), password, and optional
 * two-step sign-in. Every change is audited; security changes email the
 * person a notice and sign out their other sessions.
 */
final class AccountController extends Controller
{
    private const ISSUER = 'Argus (Southeastern)';

    /** MailService sends the security notices (email change, password change, two-step changes). */
    public function __construct(Environment $twig, private MailService $mail)
    {
        parent::__construct($twig);
    }

    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'account';
    }

    /** My profile page. */
    public function index(): void
    {
        $user = $this->requireLogin();
        $this->renderPage($user);
    }

    /** Name and job title. */
    public function saveProfile(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter your name (up to 120 characters).';
        }
        if (mb_strlen($title) > 160) {
            $errors['title'] = 'Keep the job title to 160 characters or fewer.';
        }
        if ($errors) {
            http_response_code(422);
            $this->renderPage($user, ['profile_errors' => $errors]);
            return;
        }
        UserRepository::update((int) $user['id'], $name, $title !== '' ? $title : null, $user['email'], $user['role']);
        AuditLogger::log('user', (int) $user['id'], 'profile_edit', 'Updated own name / job title',
            before: ['name' => $user['name'], 'title' => $user['title']], after: ['name' => $name, 'title' => $title ?: null]);
        $this->flash('Profile saved.');
        $this->redirect('/account');
    }

    /** Start a sign-in email change: confirmation link to the new address, notice to the old one. */
    public function requestEmailChange(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $new = strtolower(trim((string) ($_POST['new_email'] ?? '')));
        $errors = [];
        if (!filter_var($new, FILTER_VALIDATE_EMAIL) || mb_strlen($new) > 190) {
            $errors['new_email'] = 'Enter a valid email address.';
        } elseif (strcasecmp($new, $user['email']) === 0) {
            $errors['new_email'] = 'That’s already your sign-in email.';
        } elseif (($other = UserRepository::findByEmail($new)) && (int) $other['id'] !== (int) $user['id']) {
            $errors['new_email'] = 'Another account already uses that address.';
        }
        if (!$errors && !Auth::verifyCurrentPassword($user, (string) ($_POST['current_password'] ?? ''))) {
            $errors['email_password'] = 'That isn’t your current password.';
            AuditLogger::log('user', (int) $user['id'], 'email_change_failed', 'Email change refused: wrong password');
        }
        if ($errors) {
            http_response_code(422);
            $this->renderPage($user, ['email_errors' => $errors, 'new_email_value' => $new]);
            return;
        }

        $token = EmailChangeService::request((int) $user['id'], $new);
        $link = rtrim((string) Config::get('app.base_url'), '/') . '/account/email/confirm/' . $token;
        $this->mail->send('email_change_confirm', $new, 'Confirm your new sign-in email', [
            'name' => $user['name'], 'old_email' => $user['email'], 'new_email' => $new, 'cta_url' => $link,
        ]);
        $this->notify($user, "A change of your sign-in email to {$new} was requested");
        AuditLogger::log('user', (int) $user['id'], 'email_change_request', "Requested sign-in email change to {$new} (confirmation link sent there)");
        $this->flash(trim("We’ve sent a confirmation link to {$new}. Your sign-in email changes when you open it (within 24 hours). " . \App\Support\Settings::emailModeText()));
        $this->redirect('/account#email');
    }

    /** Withdraw a pending sign-in email change. */
    public function cancelEmailChange(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        EmailChangeService::cancel((int) $user['id']);
        AuditLogger::log('user', (int) $user['id'], 'email_change_cancel', 'Cancelled pending sign-in email change');
        $this->flash('Email change cancelled.');
        $this->redirect('/account#email');
    }

    /**
     * GET /account/email/confirm/{token} — a page with a Confirm button rather
     * than acting on GET, so mail scanners that pre-open links can't apply it.
     * No sign-in needed: the token proves control of the new address.
     */
    public function showConfirmEmail(array $params): void
    {
        $change = EmailChangeService::resolve((string) $params['token']);
        $this->render('auth/confirm_email.twig', [
            'token' => $change ? (string) $params['token'] : null,
            'new_email' => $change['new_email'] ?? null,
            'flash' => null,
        ]);
    }

    /** POST of the Confirm button: apply the change if the token is valid, then audit and notify. */
    public function confirmEmail(array $params): void
    {
        $this->requireCsrf();
        $token = (string) $params['token'];
        $change = EmailChangeService::resolve($token);
        $oldEmail = $change ? EmailChangeService::confirm($token) : null;
        if (!$change || $oldEmail === null) {
            http_response_code(400);
            $this->render('auth/confirm_email.twig', ['token' => null, 'new_email' => null, 'flash' => null,
                'error' => 'This link has expired, was already used, or that address is now taken. Start the change again from My profile.']);
            return;
        }
        $user = UserRepository::find((int) $change['user_id']);
        AuditLogger::log('user', (int) $change['user_id'], 'email_change', "Sign-in email changed from {$oldEmail} to {$change['new_email']}",
            before: ['email' => $oldEmail], after: ['email' => $change['new_email']], actorId: (int) $change['user_id'], actorEmail: $change['new_email']);
        if ($user) {
            $this->notify(['email' => $oldEmail] + $user, "Your sign-in email was changed to {$change['new_email']}");
        }
        $this->flash("Your sign-in email is now {$change['new_email']}. Sign in with it.");
        $this->redirect('/login');
    }

    /** Change password after re-checking the current one and the password policy; signs out other sessions. */
    public function changePassword(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $errors = [];
        if (!Auth::verifyCurrentPassword($user, $current)) {
            $errors['current_password'] = 'That isn’t your current password.';
            AuditLogger::log('user', (int) $user['id'], 'password_change_failed', 'Password change refused: wrong current password');
        }
        $weak = \App\Support\PasswordPolicy::problems($new, $user);
        if ($weak) {
            $errors['new_password'] = implode(' ', $weak);
        } elseif (strcasecmp($new, $user['email']) === 0 || ($current !== '' && hash_equals($current, $new))) {
            $errors['new_password'] = 'Choose a password different from your current one and your email.';
        } elseif (!hash_equals($new, $confirm)) {
            $errors['new_password_confirm'] = 'The two new passwords don’t match.';
        }
        if ($errors) {
            http_response_code(422);
            $this->renderPage($user, ['password_errors' => $errors]);
            return;
        }

        UserRepository::setPassword((int) $user['id'], $new);
        Auth::bumpSessionVersion((int) $user['id'], keepCurrent: true);
        AuditLogger::log('user', (int) $user['id'], 'password_change', 'Changed own password (other sessions signed out)');
        $this->notify($user, 'Your password was changed');
        $this->flash('Password changed. Any other place you were signed in has been signed out.');
        $this->redirect('/account');
    }

    /** Step 1: make a secret (kept in the session until confirmed) and show the QR code. */
    public function startTwoFactor(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        if (TwoFactorService::hasApp($user)) { // already has an app (keys alone don't block adding one)
            $this->redirect('/account#two-step');
            return;
        }
        if (!Crypto::available()) {
            $this->flash('Two-step sign-in isn’t available yet: the server’s APP_KEY is missing. Ask Admin / Tech to re-run the installer.');
            $this->redirect('/account#two-step');
            return;
        }
        $_SESSION['2fa_setup'] = ['secret' => Totp::newSecret(), 'at' => time()];
        $this->redirect('/account#two-step');
    }

    /** Step 2: confirm a code from the app, then switch it on and show recovery codes once. */
    public function enableTwoFactor(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $setup = $_SESSION['2fa_setup'] ?? null;
        if (!is_array($setup) || (time() - (int) $setup['at']) > 900) {
            unset($_SESSION['2fa_setup']);
            $this->flash('Setup timed out — start again.');
            $this->redirect('/account#two-step');
            return;
        }
        $step = TwoFactorService::verifySetupCode($setup['secret'], (string) ($_POST['code'] ?? ''));
        if ($step === null) {
            http_response_code(422);
            $this->renderPage($user, ['setup_error' => 'That code didn’t match. Make sure you scanned this page’s QR code and your phone’s clock is set automatically, then enter the newest code.']);
            return;
        }

        $codes = TwoFactorService::enable((int) $user['id'], $setup['secret'], $step);
        unset($_SESSION['2fa_setup']);
        Auth::bumpSessionVersion((int) $user['id'], keepCurrent: true);
        AuditLogger::log('user', (int) $user['id'], '2fa_enable', 'Added an authenticator app for two-step sign-in (other sessions signed out)');
        $this->notify($user, 'An authenticator app was added for two-step sign-in on your account');
        if ($codes) {
            $this->renderPage(Auth::user() ?? $user, ['new_recovery_codes' => $codes, 'just_enabled' => true]);
            return;
        }
        $this->flash('Authenticator app added. Your existing recovery codes still work.');
        $this->redirect('/account#two-step');
    }

    /** Turn off the authenticator app (refused when two-step is required for the account). */
    public function disableTwoFactor(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        if (!TwoFactorService::isEnabled($user)) {
            $this->redirect('/account#two-step');
            return;
        }
        if (TwoFactorService::isRequiredFor($user)) {
            $this->flash('Two-step sign-in is required for your account, so it can’t be turned off. You can still add or replace methods.');
            $this->redirect('/account#two-step');
            return;
        }
        if (!Auth::verifyCurrentPassword($user, (string) ($_POST['current_password'] ?? ''))) {
            AuditLogger::log('user', (int) $user['id'], '2fa_disable_failed', 'Turning off two-step sign-in refused: wrong password');
            http_response_code(422);
            $this->renderPage($user, ['disable_error' => 'That isn’t your current password.']);
            return;
        }
        TwoFactorService::disable((int) $user['id']);
        Auth::bumpSessionVersion((int) $user['id'], keepCurrent: true);
        AuditLogger::log('user', (int) $user['id'], '2fa_disable', 'Turned off two-step sign-in');
        $this->notify($user, 'Two-step sign-in was turned off for your account');
        $this->flash('Two-step sign-in is off. Your password alone signs you in now.');
        $this->redirect('/account#two-step');
    }

    /** POST (fetch) — after re-checking the password, the browser options for adding a security key. */
    public function securityKeyOptions(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        if (!Auth::verifyCurrentPassword($user, (string) ($_POST['current_password'] ?? ''))) {
            AuditLogger::log('user', (int) $user['id'], '2fa_key_add_failed', 'Adding a security key refused: wrong password');
            $this->json(['ok' => false, 'error' => 'That isn’t your current password.'], 422);
            return;
        }
        try {
            $kind = ($_POST['kind'] ?? 'key') === 'passkey' ? 'passkey' : 'key';
            $this->json(['ok' => true, 'options' => SecurityKeyService::registrationOptions($user, $kind)]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Security keys aren’t available on this server: ' . $e->getMessage()], 500);
        }
    }

    /** POST (fetch) — the key's response; verify and save it. */
    public function registerSecurityKey(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $b64 = fn (string $k) => (string) base64_decode((string) ($_POST[$k] ?? ''), true);
        try {
            SecurityKeyService::finishRegistration($user, (string) ($_POST['name'] ?? ''), $b64('clientDataJSON'), $b64('attestationObject'));
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        $codes = TwoFactorService::ensureRecoveryCodes((int) $user['id']);
        Auth::bumpSessionVersion((int) $user['id'], keepCurrent: true);
        $name = trim((string) ($_POST['name'] ?? '')) ?: 'Security key';
        AuditLogger::log('user', (int) $user['id'], '2fa_key_add', "Added security key “{$name}” for two-step sign-in (other sessions signed out)");
        $this->notify($user, "A security key (“{$name}”) was added for two-step sign-in on your account");
        if ($codes) {
            $_SESSION['show_recovery_codes'] = $codes; // shown once on the next page load
        } else {
            $this->flash("Security key “{$name}” added.");
        }
        $this->json(['ok' => true, 'redirect' => '/account#two-step']);
    }

    /** Remove one registered security key / passkey. */
    public function removeSecurityKey(array $params): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        if (TwoFactorService::isRequiredFor($user) && !TwoFactorService::hasApp($user) && SecurityKeyService::count((int) $user['id']) <= 1) {
            $this->flash('That’s your only two-step method and two-step sign-in is required, so add another key or an authenticator app before removing it.');
            $this->redirect('/account#two-step');
            return;
        }
        if (!Auth::verifyCurrentPassword($user, (string) ($_POST['current_password'] ?? ''))) {
            $this->flash('Key not removed: that isn’t your current password.');
            $this->redirect('/account#two-step');
            return;
        }
        $name = SecurityKeyService::remove((int) $user['id'], (int) $params['id']);
        if ($name !== null) {
            TwoFactorService::dropRecoveryCodesIfOff((int) $user['id']);
            Auth::bumpSessionVersion((int) $user['id'], keepCurrent: true);
            AuditLogger::log('user', (int) $user['id'], '2fa_key_remove', "Removed security key “{$name}”");
            $this->notify($user, "A security key (“{$name}”) was removed from your account");
            $this->flash("Security key “{$name}” removed.");
        }
        $this->redirect('/account#two-step');
    }

    /** Remove only the authenticator app (security keys stay). */
    public function removeApp(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        if (TwoFactorService::isRequiredFor($user) && SecurityKeyService::count((int) $user['id']) === 0) {
            $this->flash('The app is your only two-step method and two-step sign-in is required, so add a security key before removing it.');
            $this->redirect('/account#two-step');
            return;
        }
        if (!Auth::verifyCurrentPassword($user, (string) ($_POST['current_password'] ?? ''))) {
            $this->flash('App not removed: that isn’t your current password.');
            $this->redirect('/account#two-step');
            return;
        }
        TwoFactorService::disableApp((int) $user['id']);
        Auth::bumpSessionVersion((int) $user['id'], keepCurrent: true);
        AuditLogger::log('user', (int) $user['id'], '2fa_app_remove', 'Removed the authenticator app from two-step sign-in');
        $this->notify($user, 'The authenticator app was removed from two-step sign-in on your account');
        $this->flash('Authenticator app removed.');
        $this->redirect('/account#two-step');
    }

    /** Replace the recovery codes with a fresh set (shown once). */
    public function regenerateCodes(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        if (!TwoFactorService::isEnabled($user)) {
            $this->redirect('/account#two-step');
            return;
        }
        if (!Auth::verifyCurrentPassword($user, (string) ($_POST['current_password'] ?? ''))) {
            http_response_code(422);
            $this->renderPage($user, ['codes_error' => 'That isn’t your current password.']);
            return;
        }
        $codes = TwoFactorService::regenerateRecoveryCodes((int) $user['id']);
        AuditLogger::log('user', (int) $user['id'], '2fa_recovery_codes', 'Generated new two-step recovery codes (old ones no longer work)');
        $this->notify($user, 'New two-step recovery codes were generated for your account');
        $this->renderPage($user, ['new_recovery_codes' => $codes]);
    }

    private function renderPage(array $user, array $extra = []): void
    {
        $setup = $_SESSION['2fa_setup'] ?? null;
        $setupView = null;
        if (is_array($setup) && !TwoFactorService::hasApp($user)) {
            $uri = Totp::uri($setup['secret'], $user['email'], self::ISSUER);
            $qr = (new QRCode(new QROptions([
                'outputType'   => QROutputInterface::MARKUP_SVG,
                'outputBase64' => true,
                'eccLevel'     => EccLevel::M,
                'addQuietzone' => true,
            ])))->render($uri);
            $setupView = ['qr' => $qr, 'secret' => Totp::grouped($setup['secret'])];
        }

        // Recovery codes from adding a first security key (set in registerSecurityKey) — shown once.
        if (!isset($extra['new_recovery_codes']) && !empty($_SESSION['show_recovery_codes'])) {
            $extra += ['new_recovery_codes' => $_SESSION['show_recovery_codes'], 'just_enabled' => true];
        }
        unset($_SESSION['show_recovery_codes']);

        $this->render('account.twig', $extra + [
            'me' => $user,
            'has_app' => TwoFactorService::hasApp($user),
            'two_factor_required' => TwoFactorService::isRequiredFor($user),
            'security_keys' => SecurityKeyService::keysFor((int) $user['id']),
            'pending_email' => EmailChangeService::pending((int) $user['id']),
            'role_label_me' => \App\Domain\RequestRepository::ROLE_LABELS[$user['role']] ?? $user['role'],
            'password_changed' => !empty($user['password_changed_at']) ? date('M j, Y', strtotime($user['password_changed_at'])) : null,
            'two_factor_on' => TwoFactorService::isEnabled($user),
            'two_factor_since' => !empty($user['totp_enabled_at']) ? date('M j, Y', strtotime($user['totp_enabled_at'])) : null,
            'recovery_left' => TwoFactorService::remainingRecoveryCodes((int) $user['id']),
            'setup' => $setupView,
            'password_hint' => \App\Support\PasswordPolicy::hint(),
        ]);
    }

    /** Security notice to the account's own address (respects email test mode). */
    private function notify(array $user, string $event): void
    {
        $this->mail->send('security_notice', $user['email'], $event, [
            'name' => $user['name'],
            'event' => $event,
            'when' => date('M j, Y g:i A'),
            'ip' => \App\Support\IpResolver::clientIp(),
            'cta_url' => rtrim((string) Config::get('app.base_url'), '/') . '/account',
        ]);
    }
}

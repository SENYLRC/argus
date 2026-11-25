<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\MailService;
use App\Domain\PasswordResetService;
use App\Domain\SecurityKeyService;
use App\Domain\TwoFactorService;
use App\Domain\UserRepository;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Config;
use Twig\Environment;

/**
 * Sign-in (password, then an authenticator code for accounts with two-step
 * sign-in on), sign-out, and the emailed set-password link.
 */
final class AuthController extends Controller
{
    /** How long the "password OK, now enter your code" state lasts. */
    private const SECOND_STEP_TTL = 300;
    /** Wrong codes allowed before the password has to be entered again. */
    private const SECOND_STEP_TRIES = 5;

    /** MailService sends the set-password link when an admin has required a new password. */
    public function __construct(Environment $twig, private MailService $mail)
    {
        parent::__construct($twig);
    }

    /** Login form (signed-in users go to the list). */
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('/requests');
            return;
        }
        $this->render('auth/login.twig', ['flash' => null]);
    }

    /** Check the password, then either sign in or hold for the two-step code. */
    public function login(): void
    {
        $this->requireCsrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $row = Auth::checkPassword($email, $password);
        if ($row !== null && !empty($row['force_pw_reset'])) {
            $this->refuseForcedReset($row);
            return;
        }
        if ($row !== null && TwoFactorService::isEnabled($row)) {
            // Password is right; hold the sign-in until the code checks out.
            \App\Support\Session::regenerate();
            $_SESSION['2fa_pending'] = ['id' => (int) $row['id'], 'at' => time(), 'tries' => 0];
            AuditLogger::log('auth', (int) $row['id'], 'login_password_ok', 'Password accepted — waiting for two-step code', actorId: (int) $row['id'], actorEmail: $row['email']);
            $this->redirect('/login/verify');
            return;
        }
        if ($row !== null) {
            Auth::completeLogin($row);
            AuditLogger::log('user', Auth::id(), 'login', 'Signed in', actorId: Auth::id(), actorEmail: $email);
            $this->redirect('/requests');
            return;
        }

        // Failed attempts are audited too (unknown emails included, truncated) —
        // a run of these against one account is what the lockout is for.
        AuditLogger::log('auth', null, 'login_failed', 'Failed sign-in for ' . mb_substr($email, 0, 120), actorId: null, actorEmail: mb_substr($email, 0, 190) ?: '(blank)');
        http_response_code(401);
        $this->render('auth/login.twig', [
            'error' => 'Incorrect email or password, or the account is locked after too many attempts.',
            'submitted_email' => $email,
            'flash' => null,
        ]);
    }

    /**
     * An admin required a new password: the right password no longer signs the
     * person in. Email a fresh set-password link (at most one per 10 minutes)
     * and tell them, on screen, that it was sent.
     */
    private function refuseForcedReset(array $row): void
    {
        if (!PasswordResetService::recentlyIssued((int) $row['id'])) {
            $token = PasswordResetService::issue((int) $row['id']);
            $this->mail->send('set_password_invite', $row['email'], 'Choose a new password — Expenditure & Encumbrance Requests', [
                'name' => $row['name'],
                'is_reset' => true,
                'cta_url' => rtrim((string) Config::get('app.base_url'), '/') . '/set-password/' . $token,
            ]);
        }
        AuditLogger::log('auth', (int) $row['id'], 'login_forced_reset', 'Sign-in refused: a new password is required; set-password link emailed', actorId: (int) $row['id'], actorEmail: $row['email']);
        http_response_code(403);
        $this->render('auth/login.twig', [
            'error' => 'Admin / Tech requires you to choose a new password. A set-password link has been emailed to you.',
            'submitted_email' => $row['email'],
            'flash' => null,
        ]);
    }

    /** GET /login/verify — the code step. */
    public function showVerify(): void
    {
        $user = $this->pendingUser();
        if ($user === null) {
            $this->redirect('/login');
            return;
        }
        $this->render('auth/verify.twig', $this->verifyContext($user));
    }

    /** What the code step offers this person: security key button and/or app-code box. */
    private function verifyContext(array $user, array $extra = []): array
    {
        return $extra + [
            'flash' => null,
            'has_keys' => SecurityKeyService::count((int) $user['id']) > 0,
            'has_app' => TwoFactorService::hasApp($user),
        ];
    }

    /** POST (fetch) — browser options for signing in with a security key. */
    public function securityKeyOptions(): void
    {
        $this->requireCsrf();
        $user = $this->pendingUser();
        if ($user === null) {
            $this->json(['ok' => false, 'error' => 'That sign-in timed out. Enter your password again.', 'redirect' => '/login'], 401);
            return;
        }
        $this->json(['ok' => true, 'options' => SecurityKeyService::loginOptions($user)]);
    }

    /** POST (fetch) — check the key's signature and finish signing in. */
    public function securityKeyLogin(): void
    {
        $this->requireCsrf();
        $user = $this->pendingUser();
        if ($user === null) {
            $this->json(['ok' => false, 'error' => 'That sign-in timed out. Enter your password again.', 'redirect' => '/login'], 401);
            return;
        }
        $b64 = fn (string $k) => (string) base64_decode((string) ($_POST[$k] ?? ''), true);
        try {
            $ok = SecurityKeyService::verifyLogin($user, $b64('credentialId'), $b64('clientDataJSON'), $b64('authenticatorData'), $b64('signature'));
        } catch (\RuntimeException) {
            $ok = false;
        }
        if ($ok) {
            Auth::completeLogin($user);
            AuditLogger::log('user', (int) $user['id'], 'login', 'Signed in (two-step: security key)', actorId: (int) $user['id'], actorEmail: $user['email']);
            $this->json(['ok' => true, 'redirect' => '/requests']);
            return;
        }
        $_SESSION['2fa_pending']['tries']++;
        Auth::registerFailedSecondStep((int) $user['id']);
        AuditLogger::log('auth', (int) $user['id'], 'login_2fa_failed', 'Security key sign-in failed', actorId: (int) $user['id'], actorEmail: $user['email']);
        if ($_SESSION['2fa_pending']['tries'] >= self::SECOND_STEP_TRIES) {
            unset($_SESSION['2fa_pending']);
            $this->flash('Too many failed attempts. Enter your password again.');
            $this->json(['ok' => false, 'error' => 'Too many failed attempts.', 'redirect' => '/login'], 401);
            return;
        }
        $this->json(['ok' => false, 'error' => 'That security key wasn’t accepted. Use a key registered to this account, or enter a code instead.'], 401);
    }

    /** POST /login/verify — accept an app code or a recovery code. */
    public function verify(): void
    {
        $this->requireCsrf();
        $user = $this->pendingUser();
        if ($user === null) {
            $this->flash('That sign-in timed out. Enter your password again.');
            $this->redirect('/login');
            return;
        }

        $method = TwoFactorService::verify($user, (string) ($_POST['code'] ?? ''));
        if ($method !== null) {
            Auth::completeLogin($user);
            $detail = $method === 'recovery' ? 'Signed in (two-step: recovery code)' : 'Signed in (two-step: app code)';
            AuditLogger::log('user', (int) $user['id'], 'login', $detail, actorId: (int) $user['id'], actorEmail: $user['email']);
            if ($method === 'recovery') {
                $left = TwoFactorService::remainingRecoveryCodes((int) $user['id']);
                $this->flash("Signed in with a recovery code — {$left} left. If you've lost your phone, set two-step sign-in up again under My account.");
            }
            $this->redirect('/requests');
            return;
        }

        $_SESSION['2fa_pending']['tries']++;
        Auth::registerFailedSecondStep((int) $user['id']);
        AuditLogger::log('auth', (int) $user['id'], 'login_2fa_failed', 'Wrong two-step code', actorId: (int) $user['id'], actorEmail: $user['email']);
        if ($_SESSION['2fa_pending']['tries'] >= self::SECOND_STEP_TRIES) {
            unset($_SESSION['2fa_pending']);
            $this->flash('Too many wrong codes. Enter your password again.');
            $this->redirect('/login');
            return;
        }
        http_response_code(401);
        $this->render('auth/verify.twig', $this->verifyContext($user, ['error' => 'That code didn’t work. Check the time on your phone is set automatically, then try the newest code.']));
    }

    /** The user waiting at the code step, if that state is still valid. */
    private function pendingUser(): ?array
    {
        $p = $_SESSION['2fa_pending'] ?? null;
        if (!is_array($p) || (time() - (int) $p['at']) > self::SECOND_STEP_TTL || (int) $p['tries'] >= self::SECOND_STEP_TRIES) {
            unset($_SESSION['2fa_pending']);
            return null;
        }
        $user = UserRepository::find((int) $p['id']);
        if (!$user || !$user['active'] || Auth::isLocked($user) || !TwoFactorService::isEnabled($user)) {
            unset($_SESSION['2fa_pending']);
            return null;
        }
        return $user;
    }

    /** Audit and end the session. */
    public function logout(): void
    {
        $this->requireCsrf();
        AuditLogger::log('user', Auth::id(), 'logout', 'Signed out');
        Auth::logout();
        $this->redirect('/login');
    }

    /** Set-password form for a valid emailed link. */
    public function showSetPassword(array $params): void
    {
        $token = $params['token'];
        $userId = PasswordResetService::resolve($token);
        if (!$userId) {
            http_response_code(400);
            echo 'This link has expired or was already used. Ask Admin / Tech for a new one.';
            return;
        }
        $user = UserRepository::find($userId);
        $this->render('auth/set_password.twig', [
            'token' => $token,
            'user_email' => $user['email'],
            'is_first_login' => $user['password_hash'] === null,
            'password_hint' => \App\Support\PasswordPolicy::hint(), 'needs_signoff_setup' => in_array($user['role'], ['ed', 'admin'], true) && $user['signoff_pin'] === null,
            'flash' => null,
        ]);
    }

    /** Apply the new password (policy-checked), consume the token and sign the person in. */
    public function setPassword(array $params): void
    {
        $this->requireCsrf();
        $token = $params['token'];
        $userId = PasswordResetService::resolve($token);
        if (!$userId) {
            http_response_code(400);
            echo 'This link has expired or was already used. Ask Admin / Tech for a new one.';
            return;
        }
        $user = UserRepository::find($userId);

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $needsPin = in_array($user['role'], ['ed', 'admin'], true) && $user['signoff_pin'] === null;
        $pin = (string) ($_POST['pin'] ?? '');

        $error = null;
        $weak = \App\Support\PasswordPolicy::problems($password, $user);
        if ($weak) {
            $error = implode(' ', $weak);
        } elseif (!hash_equals($password, $confirm)) {
            $error = 'Passwords do not match.';
        } elseif ($needsPin && !preg_match('/^\d{4}$/', $pin)) {
            $error = 'Enter a 4-digit PIN.';
        }

        if ($error) {
            http_response_code(422);
            $this->render('auth/set_password.twig', [
                'token' => $token, 'user_email' => $user['email'],
                'is_first_login' => $user['password_hash'] === null,
                'password_hint' => \App\Support\PasswordPolicy::hint(), 'needs_signoff_setup' => $needsPin, 'error' => $error, 'flash' => null,
            ]);
            return;
        }

        UserRepository::setPassword($userId, $password);
        if ($needsPin) {
            UserRepository::setPin($userId, $pin);
        }
        PasswordResetService::consume($token);
        AuditLogger::log('user', $userId, 'set_password', 'Password set via emailed link', actorId: $userId, actorEmail: $user['email']);

        if (!Auth::attempt($user['email'], $password)) {
            // Two-step sign-in is on for this account: go through the normal sign-in.
            $this->flash('Password set. Sign in with your new password.');
            $this->redirect('/login');
            return;
        }
        $this->flash('Password set. Welcome to the Expenditure & Encumbrance Request System.');
        $this->redirect('/requests');
    }

    /** Clear the queued notice and return to the page (only to a safe local path). */
    public function dismissFlash(): void
    {
        $this->requireCsrf();
        \App\Support\Flash::clear();
        $next = (string) ($_POST['next'] ?? '/');
        $this->redirect($this->isSafeLocalPath($next) ? $next : '/');
    }
}

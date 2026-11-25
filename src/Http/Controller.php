<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Session;
use App\Support\Settings;
use Twig\Environment;

/**
 * Base for every screen: auth/role/CSRF guards, Twig rendering with the shared chrome context,
 * JSON and redirect helpers. Guards call exit after redirecting or erroring, so code after them
 * only runs for an allowed request.
 */
abstract class Controller
{
    /** Twig renders every screen; subclasses with more dependencies call parent::__construct(). */
    public function __construct(protected Environment $twig)
    {
    }

    /**
     * Redirect to /login unless signed in, and to My profile if a required two-step setup is still pending.
     * Returns the user row.
     */
    protected function requireLogin(): array
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            exit;
        }
        $user = Auth::user();
        // Two-step sign-in required by an admin but not set up yet: everything
        // except My profile (to set it up), sign-out and the accessibility page
        // sends them to set it up first.
        if (\App\Domain\TwoFactorService::setupRequired($user) && !$this->allowedBeforeTwoStepSetup()) {
            $this->flash('Two-step sign-in is required for your account. Set up an authenticator app or a security key to continue.');
            $this->redirect('/account#two-step');
            exit;
        }
        return $user;
    }

    /** Pages reachable while a required two-step setup is still pending. */
    private function allowedBeforeTwoStepSetup(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return $path === '/account' || str_starts_with($path, '/account/')
            || in_array($path, ['/logout', '/flash/dismiss', '/accessibility'], true);
    }

    /** requireLogin() plus a role check; renders the 403 page and exits when the role isn't listed. Returns the user row. */
    protected function requireRole(array $roles): array
    {
        $user = $this->requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            echo $this->twig->render('errors/403.twig', $this->baseContext());
            exit;
        }
        return $user;
    }

    /** Reject (HTTP 400) any POST whose _csrf field doesn't match the session token. Call first in every state-changing action. */
    protected function requireCsrf(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Your session expired — go back and try again.';
            exit;
        }
    }

    /** Echo a Twig template with the shared chrome context (baseContext) merged under $context. */
    protected function render(string $template, array $context = []): void
    {
        echo $this->twig->render($template, array_merge($this->baseContext(), $context));
    }

    /** JSON response (security-key steps run from the browser via fetch). */
    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    /** Send a Location header. Callers must return (or exit) afterwards; use isSafeLocalPath() for user-supplied targets. */
    protected function redirect(string $path): void
    {
        header('Location: ' . $path);
    }

    /**
     * True only for a same-origin, path-only redirect target. Guards every
     * user-suppliable "return here after" value (flash dismiss, signature
     * upload) against open-redirect: rejects not just `//host` (parsed as an
     * absolute URL by browsers) but also `/\host` — some browsers normalize
     * a leading backslash to a second slash before navigating, which would
     * otherwise slip past a naive "starts with one slash" check.
     */
    protected function isSafeLocalPath(string $path): bool
    {
        return (bool) preg_match('#^/(?!/|\\\\)#', $path);
    }

    /** Queue a notice shown (and focused for screen readers) on the next page. */
    protected function flash(string $message): void
    {
        Flash::set($message);
    }

    /** Tabs, chrome, active user — shared by every screen template (README "Screens / views"). */
    protected function baseContext(): array
    {
        $user = Auth::user();
        $flash = Flash::peek();

        return [
            'current_user' => $user,
            'role'         => $user['role'] ?? null,
            'role_label'   => $user ? (\App\Domain\RequestRepository::ROLE_LABELS[$user['role']] ?? $user['role']) : null,
            'tabs'         => $user ? $this->tabsFor($user['role']) : [],
            'flash'        => $flash,
            'csrf_field'   => Csrf::field(),
            'csrf_token'   => Csrf::token(), // for fetch() posts (security-key steps)
            'base_url'     => Config::get('app.base_url'),
            'active_tab'   => $this->activeTab(),
            'current_path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            // Email test mode warning bar — admins only, like linx's email_mode_bar.
            'email_mode_text' => ($user['role'] ?? null) === 'admin' ? Settings::emailModeText() : '',
            // Test-site banner — everyone, signed in or not (Administration > Site banner).
            'site_banner'     => Settings::siteBanner(),
        ];
    }

    private function tabsFor(string $role): array
    {
        $list = ['id' => 'list', 'label' => $role === 'ed' ? 'Approval queue' : ($role === 'bookkeeper' ? 'Approved requests' : 'All requests')];
        $mine = ['id' => 'mine', 'label' => 'My requests'];
        $new = ['id' => 'new', 'label' => 'New request'];
        $batch = ['id' => 'batch', 'label' => 'Monthly batch'];
        $users = ['id' => 'users', 'label' => 'Accounts'];
        $admin = ['id' => 'admin', 'label' => 'Administration'];
        $emails = ['id' => 'emails', 'label' => 'Email notices'];

        return match ($role) {
            'staff'      => [$mine, $new, $batch, $emails],
            'admin'      => [$list, $new, $batch, $users, $admin, $emails],
            default      => [$list, $new, $batch, $users, $emails], // ed, bookkeeper
        };
    }

    /** Override in a controller to mark which tab is current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'list';
    }
}

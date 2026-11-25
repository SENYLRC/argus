<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\BudgetLineRepository;
use App\Domain\MailService;
use App\Domain\PasswordResetService;
use App\Domain\RequestRepository;
use App\Domain\UserRepository;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Settings;
use Twig\Environment;

/**
 * Shared by admin/ED/bookkeeper (build-notes §2b) — role is checked per
 * action, not just gating the whole controller, since the permission table
 * differs action by action.
 */
final class UsersController extends Controller
{
    /** MailService sends account invitations and reset links. */
    public function __construct(Environment $twig, private MailService $mail)
    {
        parent::__construct($twig);
    }

    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'users';
    }

    /** Accounts list with the role guide. */
    public function index(): void
    {
        $this->requireRole(['admin', 'ed', 'bookkeeper']);
        $this->renderList(null);
    }

    /** Blank add-account form. */
    public function showNew(): void
    {
        $this->requireRole(['admin', 'ed', 'bookkeeper']);
        $this->renderList(['id' => null, 'name' => '', 'title' => '', 'email' => '', 'role' => 'staff', 'budget_line_keys' => []]);
    }

    /** Edit form for an account the viewer may manage. */
    public function showEdit(array $params): void
    {
        $actor = $this->requireRole(['admin', 'ed', 'bookkeeper']);
        $target = UserRepository::find((int) $params['id']);
        if (!$target || !$this->canManage($actor, $target)) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }
        $this->renderList(['id' => $target['id'], 'name' => $target['name'], 'title' => $target['title'], 'email' => $target['email'], 'role' => $target['role'],
            'budget_line_keys' => BudgetLineRepository::assignedKeys((int) $target['id'])]);
    }

    /** Add an account and email its 48-hour set-password link. */
    public function create(): void
    {
        $actor = $this->requireRole(['admin', 'ed', 'bookkeeper']);
        $this->requireCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? '')) ?: null;
        $role = $this->resolveRole($actor, (string) ($_POST['role'] ?? 'staff'));

        if ($name === '' || $email === '') {
            $this->flash('Name and email are required.');
            $this->redirect('/users/new');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('Enter a valid email address.');
            $this->redirect('/users/new');
            return;
        }
        if (UserRepository::findByEmail($email)) {
            $this->flash('An account with that email already exists.');
            $this->redirect('/users/new');
            return;
        }

        $id = UserRepository::create($name, $title, $email, $role);
        // Assigned by account number ("fy|code"): covers the line in every class.
        $lineKeys = in_array($role, BudgetLineRepository::RESTRICTED_ROLES, true) ? array_map('strval', (array) ($_POST['budget_lines'] ?? [])) : [];
        if ($lineKeys) {
            BudgetLineRepository::setAssignmentsByKeys($id, $lineKeys);
        }
        $token = PasswordResetService::issue($id);
        $this->mail->send('set_password_invite', $email, 'Set your password — Expenditure & Encumbrance Requests', [
            'name' => $name,
            'cta_url' => rtrim((string) Config::get('app.base_url'), '/') . '/set-password/' . $token,
        ]);

        AuditLogger::log('user', $id, 'create', "Created account {$email} · " . RequestRepository::ROLE_LABELS[$role] . ' · invite sent'
            . ($lineKeys ? ' · ' . count($lineKeys) . ' budget line(s) assigned' : ''), after: ['budget_lines' => $lineKeys]);
        $this->flash(trim("Account created for {$email}. A set-password link has been emailed, valid 48 hours. " . Settings::emailModeText()));
        $this->redirect('/users');
    }

    /** Save name, title, email and (admin only) role. */
    public function update(array $params): void
    {
        $actor = $this->requireRole(['admin', 'ed', 'bookkeeper']);
        $this->requireCsrf();
        $id = (int) $params['id'];
        $target = UserRepository::find($id);
        if (!$target || !$this->canManage($actor, $target)) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? '')) ?: null;
        $role = $this->resolveRole($actor, (string) ($_POST['role'] ?? $target['role']), $target['role']);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('Enter a name and a valid email address.');
            $this->redirect("/users/{$id}/edit");
            return;
        }
        $existing = UserRepository::findByEmail($email);
        if ($existing && (int) $existing['id'] !== $id) {
            $this->flash('An account with that email already exists.');
            $this->redirect("/users/{$id}/edit");
            return;
        }

        $before = ['name' => $target['name'], 'title' => $target['title'], 'email' => $target['email'], 'role' => $target['role'],
                   'budget_lines' => BudgetLineRepository::assignedKeys($id)];
        UserRepository::update($id, $name, $title, $email, $role);
        // Staff and Admin / Tech are restricted to their lines; ED and bookkeeper always see every line.
        BudgetLineRepository::setAssignmentsByKeys($id, in_array($role, BudgetLineRepository::RESTRICTED_ROLES, true) ? array_map('strval', (array) ($_POST['budget_lines'] ?? [])) : []);
        $after = ['name' => $name, 'title' => $title, 'email' => $email, 'role' => $role,
                  'budget_lines' => BudgetLineRepository::assignedKeys($id)];
        AuditLogger::log('user', $id, 'edit', "Edited account {$email} · role " . RequestRepository::ROLE_LABELS[$role]
            . ($before['budget_lines'] !== $after['budget_lines'] ? ' · budget lines changed (' . count($after['budget_lines']) . ' assigned)' : ''),
            before: $before, after: $after);
        $this->flash("{$name}'s account updated.");
        $this->redirect('/users');
    }

    /** Clear password, PIN and two-step sign-in, sign out sessions and email a fresh set-password link. */
    public function reset(array $params): void
    {
        $this->requireRole(['admin', 'ed', 'bookkeeper']);
        $this->requireCsrf();
        $id = (int) $params['id'];
        $target = UserRepository::find($id);
        if (!$target) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }

        UserRepository::clearCredentials($id);
        $token = PasswordResetService::issue($id);
        $this->mail->send('set_password_invite', $target['email'], 'Reset your password — Expenditure & Encumbrance Requests', [
            'name' => $target['name'],
            'is_reset' => true,
            'cta_url' => rtrim((string) Config::get('app.base_url'), '/') . '/set-password/' . $token,
        ]);

        AuditLogger::log('user', $id, 'reset', "Reset credentials for {$target['email']}");
        $suffix = !in_array($target['role'], ['ed', 'admin'], true) ? '.' : ' — sign-off PIN cleared and must be set again.';
        $this->flash(trim("Password reset link emailed to {$target['email']}{$suffix} " . Settings::emailModeText()));
        $this->redirect('/users');
    }

    /** Deactivate or reactivate an account (history is kept). */
    public function toggle(array $params): void
    {
        $this->requireRole(['admin', 'ed', 'bookkeeper']);
        $this->requireCsrf();
        $id = (int) $params['id'];
        $target = UserRepository::find($id);
        if (!$target) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }

        $newActive = !$target['active'];
        UserRepository::setActive($id, (bool) $newActive);
        AuditLogger::log('user', $id, $newActive ? 'reactivate' : 'deactivate', ($newActive ? 'Reactivated ' : 'Deactivated ') . $target['email']);
        $this->flash($target['name'] . ($newActive ? ' reactivated.' : ' deactivated — history and signatures kept.'));
        $this->redirect('/users');
    }

    /** Permanently delete an account (admin only; blocked once the person has signed off a request). */
    public function delete(array $params): void
    {
        $actor = $this->requireRole(['admin']);
        $this->requireCsrf();
        $id = (int) $params['id'];
        $target = UserRepository::find($id);

        if (!$target || $id === (int) $actor['id']) {
            $this->flash('That account cannot be deleted.');
            $this->redirect('/users');
            return;
        }
        if (UserRepository::hasSignoffs($id)) {
            $this->flash('This account has signed off on a request and cannot be deleted — deactivate it instead.');
            $this->redirect('/users');
            return;
        }

        UserRepository::delete($id);
        AuditLogger::log('user', $id, 'delete', "Deleted account {$target['email']}");
        $this->flash("{$target['name']}'s account deleted. Past requests stay attributed to the name on record.");
        $this->redirect('/users');
    }

    /**
     * Non-admin editors (ED, bookkeeper) may only add/edit *staff* accounts
     * (build-notes §2b: "Add / edit staff account") — they can't rename or
     * re-email another approver or the admin account, even though they
     * could never escalate that account's role (resolveRole() blocks that
     * separately).
     */
    private function canManage(array $actor, array $target): bool
    {
        return $actor['role'] === 'admin' || $target['role'] === 'staff';
    }

    /** Non-admin editors can never grant/change ed, bookkeeper or admin roles (build-notes §2b). */
    private function resolveRole(array $actor, string $requested, string $fallback = 'staff'): string
    {
        if ($actor['role'] === 'admin') {
            return in_array($requested, ['staff', 'bookkeeper', 'ed', 'admin'], true) ? $requested : $fallback;
        }
        return $fallback === 'staff' ? 'staff' : $fallback;
    }

    private function renderList(?array $draft): void
    {
        $actor = Auth::user();
        $users = UserRepository::all();
        $counts = UserRepository::counts();

        $roleGuide = [];
        foreach (['admin', 'ed', 'bookkeeper', 'staff'] as $role) {
            $roleGuide[] = [
                'role' => RequestRepository::ROLE_LABELS[$role],
                'count_label' => $counts[$role] . ($counts[$role] === 1 ? ' account' : ' accounts'),
                'caps' => $this->capsFor($role),
            ];
        }

        $viewUsers = array_map(function ($u) use ($actor) {
            // Only the ED and Admin / Tech sign off (with PIN + signature); staff and the bookkeeper don't.
            $u['credentials_label'] = !in_array($u['role'], ['ed', 'admin'], true)
                ? 'Password only — no sign-off needed'
                : (($u['signoff_pin'] ? 'PIN set' : 'PIN not set') . ' · ' . $this->signatureCountLabel((int) $u['id']));
            $u['can_delete'] = Auth::isAdmin() && (int) $u['id'] !== (int) Auth::id();
            $u['can_manage'] = $this->canManage($actor, $u);
            // e.g. "app + 2 security keys" / "off"
            $keys = \App\Domain\SecurityKeyService::count((int) $u['id']);
            $methods = array_filter([
                \App\Domain\TwoFactorService::hasApp($u) ? 'app' : null,
                $keys ? $keys . ' security key' . ($keys > 1 ? 's' : '') : null,
            ]);
            $u['two_factor'] = $methods ? implode(' + ', $methods) : null;
            if (in_array($u['role'], BudgetLineRepository::RESTRICTED_ROLES, true)) {
                $n = count(BudgetLineRepository::assignedKeys((int) $u['id']));
                $u['lines_label'] = $n ? "{$n} budget line" . ($n > 1 ? 's' : '') . ' + shared lines' : 'All budget lines (none assigned yet)';
            } else {
                $u['lines_label'] = 'All budget lines';
            }
            return $u;
        }, $users);

        $draftView = null;
        if ($draft !== null) {
            $draftView = [
                'name' => $draft['name'], 'title' => $draft['title'], 'email' => $draft['email'], 'role' => $draft['role'],
                'budget_line_keys' => $draft['budget_line_keys'] ?? [],
                'heading' => $draft['id'] ? 'Edit account' : 'New account',
                'save_label' => $draft['id'] ? 'Save changes' : 'Create account & send invite',
                'action' => $draft['id'] ? "/users/{$draft['id']}" : '/users',
            ];
        }

        $isAdmin = Auth::isAdmin();
        $this->render('users.twig', [
            'users' => $viewUsers,
            'role_labels' => RequestRepository::ROLE_LABELS,
            'role_guide' => $roleGuide,
            'draft' => $draftView,
            'line_groups' => $draft !== null ? BudgetLineRepository::codesForAssignment() : [],
            'role_locked' => !$isAdmin,
            'is_admin' => $isAdmin,
            'assignable_roles' => $isAdmin
                ? ['staff' => 'Staff', 'bookkeeper' => 'Bookkeeper', 'ed' => 'Executive Director', 'admin' => 'Admin / Tech']
                : ['staff' => 'Staff'],
            'users_blurb' => $isAdmin
                ? 'Eight accounts. You can add, edit, deactivate or delete any of them, change roles, and reset passwords and sign-off PINs.'
                : "You can add and edit staff accounts, reset passwords and sign-off PINs, and deactivate anyone who leaves. Granting approver or admin roles, and permanent deletion, stay with Admin / Tech.",
        ]);
    }

    private function signatureCountLabel(int $userId): string
    {
        $count = count(\App\Domain\SignatureRepository::forUser($userId));
        return $count ? "{$count} signature" . ($count > 1 ? 's' : '') . ' on file' : 'no signature uploaded';
    }

    private function capsFor(string $role): string
    {
        return match ($role) {
            'admin' => 'Everything, plus accounts & audit log',
            'ed' => 'Approve, reject, sign off, manage accounts',
            'bookkeeper' => 'See and print every request (payment is done in QuickBooks), manage staff accounts',
            default => 'Submit, edit & delete own unapproved requests',
        };
    }
}

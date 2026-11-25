<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\MailService;
use App\Domain\PasswordResetService;
use App\Domain\SecurityKeyService;
use App\Domain\TwoFactorService;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Db;
use App\Support\PasswordPolicy;
use App\Support\Settings;
use Twig\Environment;

/**
 * Administration > Security: require two-step sign-in (everyone or the
 * privileged roles), the password policy, who still needs two-step set up,
 * and resetting two-step sign-in for one person or everyone. Admin only;
 * every change is audited.
 */
final class AdminSecurityController extends Controller
{
    /** MailService notifies people whose two-step sign-in is reset. */
    public function __construct(Environment $twig, private MailService $mail)
    {
        parent::__construct($twig);
    }

    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** Security page: two-step requirement, password policy and who still needs two-step set up. */
    public function index(): void
    {
        $this->requireRole(['admin']);
        $people = array_map(function ($u) {
            $keys = SecurityKeyService::count((int) $u['id']);
            return [
                'id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'],
                'methods' => implode(' + ', array_filter([TwoFactorService::hasApp($u) ? 'app' : null, $keys ? "{$keys} key" . ($keys > 1 ? 's' : '') : null])),
                'required' => TwoFactorService::isRequiredFor($u),
            ];
        }, Db::conn()->query('SELECT * FROM users WHERE active = 1 ORDER BY name')->fetchAll());

        $this->render('admin/security.twig', [
            'admin_section' => 'security',
            'require_2fa' => TwoFactorService::requirement(),
            'min_length' => PasswordPolicy::minLength(),
            'min_score' => PasswordPolicy::minScore(),
            'check_breached' => Settings::get('password_check_breached', '1') === '1',
            'people' => $people,
            'missing' => array_values(array_filter($people, fn ($p) => $p['required'] && $p['methods'] === '')),
            'role_labels' => \App\Domain\RequestRepository::ROLE_LABELS,
        ]);
    }

    /** Save the two-step requirement and password policy. */
    public function save(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $require = (string) ($_POST['require_2fa'] ?? '');
        $require = in_array($require, ['privileged', 'all'], true) ? $require : '';
        $minLength = max(8, min(64, (int) ($_POST['min_length'] ?? 12)));
        $minScore = max(0, min(4, (int) ($_POST['min_score'] ?? 3)));
        $breached = isset($_POST['check_breached']) ? '1' : '0';

        $before = ['require_2fa' => TwoFactorService::requirement(), 'min_length' => PasswordPolicy::minLength(),
                   'min_score' => PasswordPolicy::minScore(), 'check_breached' => Settings::get('password_check_breached', '1')];
        Settings::set('require_2fa', $require);
        Settings::set('password_min_length', (string) $minLength);
        Settings::set('password_min_score', (string) $minScore);
        Settings::set('password_check_breached', $breached);
        $after = ['require_2fa' => $require, 'min_length' => $minLength, 'min_score' => $minScore, 'check_breached' => $breached];

        $label = ['' => 'optional', 'privileged' => 'required for ED, bookkeeper and Admin / Tech', 'all' => 'required for everyone'][$require];
        AuditLogger::log('setting', null, 'security_policy', "Security settings: two-step sign-in {$label}; passwords ≥ {$minLength} characters, strength ≥ {$minScore}, breach check " . ($breached === '1' ? 'on' : 'off'),
            before: $before, after: $after);
        $this->flash('Security settings saved.' . ($require !== '' ? ' People who still need two-step sign-in will be asked to set it up the next time they open a page.' : ''));
        $this->redirect('/admin/security');
    }

    /** Reset one person's two-step sign-in (password unchanged). Also reachable from Accounts. */
    public function resetOne(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $target = \App\Domain\UserRepository::find((int) $params['id']);
        $back = $this->isSafeLocalPath((string) ($_POST['return_to'] ?? '')) ? (string) $_POST['return_to'] : '/admin/security';
        if (!$target) {
            $this->flash('That account no longer exists.');
            $this->redirect($back);
            return;
        }
        $this->resetUser($target, 'reset by Admin / Tech');
        AuditLogger::log('user', (int) $target['id'], '2fa_admin_reset', "Reset two-step sign-in for {$target['email']} (app, security keys and recovery codes cleared; signed out everywhere)");
        $this->flash("Two-step sign-in reset for {$target['name']}. They’ve been signed out" . (TwoFactorService::isRequiredFor($target) ? ' and will set it up again at their next sign-in.' : '.'));
        $this->redirect($back);
    }

    /** Reset everyone's two-step sign-in — needs the admin's password and a typed confirmation. */
    public function resetAll(): void
    {
        $admin = $this->requireRole(['admin']);
        $this->requireCsrf();
        if (trim((string) ($_POST['confirm'] ?? '')) !== 'RESET ALL') {
            $this->flash('Nothing reset: type RESET ALL to confirm.');
            $this->redirect('/admin/security#reset-all');
            return;
        }
        if (!Auth::verifyCurrentPassword($admin, (string) ($_POST['current_password'] ?? ''))) {
            AuditLogger::log('setting', null, '2fa_reset_all_failed', 'Reset of everyone’s two-step sign-in refused: wrong password');
            $this->flash('Nothing reset: that isn’t your password.');
            $this->redirect('/admin/security#reset-all');
            return;
        }
        $includeSelf = isset($_POST['include_self']);
        $count = 0;
        foreach (Db::conn()->query('SELECT * FROM users')->fetchAll() as $u) {
            if (!$includeSelf && (int) $u['id'] === (int) $admin['id']) {
                continue;
            }
            if (!TwoFactorService::isEnabled($u)) {
                continue;
            }
            $this->resetUser($u, 'reset for every account by Admin / Tech');
            $count++;
        }
        AuditLogger::log('setting', null, '2fa_reset_all', "Reset two-step sign-in for {$count} account(s)" . ($includeSelf ? ' (including own)' : ' (own kept)'));
        $this->flash("Two-step sign-in reset for {$count} account(s). Each was signed out and emailed" . (TwoFactorService::requirement() !== '' ? '; anyone it’s required for will set it up again at their next sign-in.' : '.'));
        $this->redirect('/admin/security');
    }

    /**
     * Require new passwords for the chosen roles: each active person in them is
     * signed out everywhere, can't sign in with the old password, and is emailed
     * a set-password link. Their two-step sign-in is left as it is.
     */
    public function forcePasswords(): void
    {
        $admin = $this->requireRole(['admin']);
        $this->requireCsrf();
        $roles = array_values(array_intersect(array_keys(\App\Domain\RequestRepository::ROLE_LABELS), (array) ($_POST['roles'] ?? [])));
        if (!$roles) {
            $this->flash('Nothing changed: choose at least one role.');
            $this->redirect('/admin/security#force-pw');
            return;
        }
        $includeSelf = isset($_POST['include_self']);
        $marks = implode(',', array_fill(0, count($roles), '?'));
        $stmt = Db::conn()->prepare("SELECT * FROM users WHERE active = 1 AND role IN ({$marks})");
        $stmt->execute($roles);
        $count = 0;
        foreach ($stmt->fetchAll() as $u) {
            $isMe = (int) $u['id'] === (int) $admin['id'];
            if ($isMe && !$includeSelf) {
                continue;
            }
            Db::conn()->prepare('UPDATE users SET force_pw_reset = 1 WHERE id = :id')->execute(['id' => $u['id']]);
            Auth::bumpSessionVersion((int) $u['id'], keepCurrent: $isMe);
            $token = PasswordResetService::issue((int) $u['id']);
            $this->mail->send('set_password_invite', $u['email'], 'Choose a new password — Expenditure & Encumbrance Requests', [
                'name' => $u['name'],
                'is_reset' => true,
                'cta_url' => rtrim((string) Config::get('app.base_url'), '/') . '/set-password/' . $token,
            ]);
            $count++;
        }
        AuditLogger::log('setting', null, 'force_password_reset', "Required a new password for {$count} account(s) (" . implode(', ', $roles) . ')' . ($includeSelf ? ', including own' : ''));
        $this->flash("A new password is now required for {$count} account(s); each was signed out and emailed a link. " . Settings::emailModeText());
        $this->redirect('/admin/security#force-pw');
    }

    /** Clear app, keys and recovery codes; sign them out everywhere; tell them. */
    private function resetUser(array $u, string $why): void
    {
        TwoFactorService::disable((int) $u['id']);
        $isMe = (int) $u['id'] === (int) Auth::id();
        Auth::bumpSessionVersion((int) $u['id'], keepCurrent: $isMe);
        $this->mail->send('security_notice', $u['email'], 'Two-step sign-in was reset on your account', [
            'name' => $u['name'],
            'event' => "Two-step sign-in was {$why}",
            'when' => date('M j, Y g:i A'),
            'ip' => \App\Support\IpResolver::clientIp(),
            'cta_url' => rtrim((string) Config::get('app.base_url'), '/') . '/account',
        ]);
    }
}

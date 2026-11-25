<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Http\Controller;
use App\Support\Db;
use App\Support\IpGuard;
use App\Support\IpResolver;
use App\Support\Settings;

/**
 * Administration > IP protection: sign-in lockout, optional scanner lockout,
 * and the allow / block lists (see App\Support\IpGuard). Admin only; every
 * change and every lifted lockout is audited.
 */
final class AdminProtectionController extends Controller
{
    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** Settings form, lockouts in force and recent lockouts. */
    public function index(): void
    {
        $this->requireRole(['admin']);
        $recent = Db::conn()->query(
            "SELECT at, detail FROM audit_log WHERE action = 'ip_lockout' ORDER BY id DESC LIMIT 20"
        )->fetchAll();

        $this->render('admin/protection.twig', [
            'admin_section' => 'protection',
            'my_ip' => IpResolver::clientIp(),
            'switched_off' => getenv('ARGUS_DISABLE_IP_GUARD') !== false && getenv('ARGUS_DISABLE_IP_GUARD') !== '' && getenv('ARGUS_DISABLE_IP_GUARD') !== '0',
            's' => [
                'enabled' => Settings::get('lp_enabled', '1') === '1',
                'failed' => (int) Settings::get('lp_failed_logins', '10'),
                'timeframe_min' => (int) round((int) Settings::get('lp_timeframe', '600') / 60),
                'duration' => (int) Settings::get('lp_duration', '15'),
                'permanent' => Settings::get('lp_permanent', '0') === '1',
                'scan_enabled' => Settings::get('lp_404_enabled', '0') === '1',
                'scan_hits' => (int) Settings::get('lp_404_hits', '20'),
                'scan_window_min' => (int) round((int) Settings::get('lp_404_timeframe', '300') / 60),
                'allowlist' => Settings::get('lp_allowlist'),
                'blocklist' => Settings::get('lp_blocklist'),
            ],
            'bans' => IpGuard::activeBans(),
            'recent' => $recent,
        ]);
    }

    /** Validate and save the settings. Refuses a block list containing the admin's own address. */
    public function save(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();

        $allow = trim((string) ($_POST['allowlist'] ?? ''));
        $block = trim((string) ($_POST['blocklist'] ?? ''));
        $bad = array_merge(IpGuard::invalidEntries($allow), IpGuard::invalidEntries($block));
        if ($bad) {
            $this->flash('Not saved: “' . implode('”, “', array_slice($bad, 0, 3)) . '” isn’t a valid address or range (use 203.0.113.5 or 203.0.113.0/24, one per line).');
            $this->redirect('/admin/protection');
            return;
        }
        if (IpGuard::inList(IpResolver::clientIp(), $block)) {
            $this->flash('Not saved: the block list contains your own address (' . IpResolver::clientIp() . '), which would lock you out.');
            $this->redirect('/admin/protection');
            return;
        }

        $values = [
            'lp_enabled' => isset($_POST['enabled']) ? '1' : '0',
            'lp_failed_logins' => (string) max(3, min(1000, (int) ($_POST['failed'] ?? 10))),
            'lp_timeframe' => (string) (max(1, min(1440, (int) ($_POST['timeframe_min'] ?? 10))) * 60),
            'lp_duration' => (string) max(1, min(10080, (int) ($_POST['duration'] ?? 15))),
            'lp_permanent' => isset($_POST['permanent']) ? '1' : '0',
            'lp_404_enabled' => isset($_POST['scan_enabled']) ? '1' : '0',
            'lp_404_hits' => (string) max(3, min(1000, (int) ($_POST['scan_hits'] ?? 20))),
            'lp_404_timeframe' => (string) (max(1, min(1440, (int) ($_POST['scan_window_min'] ?? 5))) * 60),
            'lp_allowlist' => $allow,
            'lp_blocklist' => $block,
        ];
        $before = [];
        foreach ($values as $k => $v) {
            $before[$k] = Settings::get($k);
            Settings::set($k, $v);
        }
        AuditLogger::log('setting', null, 'ip_protection', 'IP protection settings saved (' . ($values['lp_enabled'] === '1' ? 'on' : 'off') . ')', before: $before, after: $values);
        $this->flash('IP protection settings saved.');
        $this->redirect('/admin/protection');
    }

    /** Lift all lockouts for one address. */
    public function unlock(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->flash('That isn’t a valid address.');
        } else {
            IpGuard::unlock($ip);
            AuditLogger::log('security', null, 'ip_unlock', "Lifted the lockout for {$ip}");
            $this->flash("Lockout lifted for {$ip}.");
        }
        $this->redirect('/admin/protection');
    }
}

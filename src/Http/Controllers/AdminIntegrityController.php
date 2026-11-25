<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\FileMonitor;
use App\Domain\MailService;
use App\Http\Controller;
use App\Support\Settings;
use Twig\Environment;

/**
 * Administration > File integrity: the last scan of the application's code
 * (see App\Domain\FileMonitor), a Scan now button, Accept changes (re-take the
 * baseline), and the monitor's settings. Admin only; audited.
 */
final class AdminIntegrityController extends Controller
{
    /** MailService sends the change alert when a manual scan finds something new. */
    public function __construct(Environment $twig, private MailService $mail)
    {
        parent::__construct($twig);
    }

    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** Last result and settings. */
    public function index(): void
    {
        $this->requireRole(['admin']);
        $this->render('admin/integrity.twig', [
            'admin_section' => 'integrity',
            'r' => FileMonitor::lastResult(),
            'enabled' => FileMonitor::enabled(),
            'extensions' => Settings::get('fm_extensions'),
            'exclude' => Settings::get('fm_exclude'),
        ]);
    }

    /** Scan now (emails the admins if the changes are new). */
    public function scan(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $r = FileMonitor::run($this->mail);
        AuditLogger::log('integrity', null, 'scan', 'File integrity scan run from Administration: ' . $r['status']);
        $this->flash($r['status'] === 'changes' ? 'Scan finished: changes found (listed below).' : 'Scan finished: no changes since the baseline.');
        $this->redirect('/admin/integrity');
    }

    /** Accept everything as it is now: take a new baseline. */
    public function accept(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $n = FileMonitor::rebaseline();
        AuditLogger::log('integrity', null, 'accept', "File integrity baseline re-taken ({$n} files)");
        $this->flash("Changes accepted: new baseline of {$n} files.");
        $this->redirect('/admin/integrity');
    }

    /** Save the on/off switch, watched extensions and excluded paths. */
    public function save(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $ext = implode(',', array_filter(array_map(
            static fn ($e) => preg_replace('/[^a-z0-9]/', '', strtolower(trim($e))),
            explode(',', (string) ($_POST['extensions'] ?? ''))
        )));
        $exclude = trim((string) ($_POST['exclude'] ?? ''));
        if ($ext === '') {
            $this->flash('Not saved: list at least one file extension to watch.');
            $this->redirect('/admin/integrity');
            return;
        }
        Settings::set('fm_enabled', isset($_POST['enabled']) ? '1' : '0');
        Settings::set('fm_extensions', $ext);
        Settings::set('fm_exclude', $exclude);
        AuditLogger::log('setting', null, 'file_monitor', 'File integrity settings saved. Re-take the baseline for the new file types to count.');
        $this->flash('File integrity settings saved. Choose Accept changes to re-take the baseline if you changed the file types or exclusions.');
        $this->redirect('/admin/integrity');
    }
}

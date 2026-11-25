<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\MailService;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Settings;
use Twig\Environment;

/**
 * Administration > Email: test mode (after linx's ILL → Contacts & Email),
 * sender details, per-notice switches, a test send, and the recent log.
 */
final class AdminEmailController extends Controller
{
    /** Notices an admin may switch off. Set-password links are always sent. */
    private const NOTICES = [
        'rejected'           => ['Request not approved', 'To the requester, with the approver’s reason, when a request is rejected.'],
        'weekly_digest'      => ['Weekly pending digest', 'Mondays 7:00 to the Executive Director and bookkeeper, when anything is waiting.'],
        'recurring_reminder' => ['Monthly recurring reminder', '1st of the month 6:00 to everyone with recurring lines on their batch sheet.'],
        'file_changes'       => ['File changes detected', 'To Admin / Tech when the nightly file integrity scan finds changed, new or removed code files.'],
    ];

    /** MailService is used for the test send. */
    public function __construct(Environment $twig, private MailService $mail)
    {
        parent::__construct($twig);
    }

    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** Email settings page with the recent send log. */
    public function index(): void
    {
        $this->requireRole(['admin']);

        $log = array_map(fn ($e) => [
            'when'    => date('M j, Y g:i A', strtotime($e['sent_at'])),
            'to'      => $e['to_email'],
            'subject' => $e['subject'],
            'ok'      => (bool) $e['ok'],
            'result'  => !$e['ok'] ? 'Failed: ' . ($e['error'] ?? 'unknown error') : ($e['delivery'] ?? 'Sent'),
        ], MailService::recentLog(50));

        $notices = [];
        foreach (self::NOTICES as $key => [$label, $hint]) {
            $notices[] = ['key' => $key, 'label' => $label, 'hint' => $hint, 'on' => Settings::noticeEnabled($key)];
        }

        $this->render('admin/email.twig', [
            'admin_section'   => 'email',
            'email_mode'      => Settings::emailMode(),
            'email_test_to'   => Settings::get('email_test_to'),
            'from_name'       => Settings::get('mail_from_name'),
            'from_email'      => Settings::get('mail_from_email'),
            'default_from_name'  => (string) Config::get('mail.from_name'),
            'default_from_email' => (string) Config::get('mail.from_email'),
            'reply_to_approver'  => Settings::get('mail_reply_to_approver', '1') === '1',
            'notices'         => $notices,
            'email_log'       => $log,
            'me_email'        => Auth::user()['email'] ?? '',
        ]);
    }

    /** Set test mode (send normally / redirect to test addresses / send nothing). */
    public function saveMode(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();

        $mode = (string) ($_POST['email_mode'] ?? 'off');
        if (!in_array($mode, ['', 'redirect', 'off'], true)) {
            $mode = 'off';
        }
        $raw = $this->addressList((string) ($_POST['email_test_to'] ?? ''));
        if ($raw === null) {
            return;
        }
        if ($mode === 'redirect' && !$raw) {
            $this->back('Not saved: “Send all email to the test address” needs at least one test address.', '#test-mode');
            return;
        }

        Settings::set('email_mode', $mode);
        Settings::set('email_test_to', implode(', ', $raw));
        $label = ['' => 'send normally', 'redirect' => 'send all email to ' . implode(', ', $raw), 'off' => 'send nothing (log only)'][$mode];
        AuditLogger::log('setting', null, 'email_mode', "Email test mode set to: {$label}");
        $this->back($mode === '' ? 'Email test mode is off — email now goes to the real recipients.' : 'Saved. ' . Settings::emailModeText(), '#test-mode');
    }

    /** Save sender name/address and the per-notice on/off switches. */
    public function saveOptions(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();

        $fromName = trim((string) ($_POST['from_name'] ?? ''));
        $fromEmail = trim((string) ($_POST['from_email'] ?? ''));
        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->back('Not saved: “' . $fromEmail . '” isn’t a valid From address.', '#options');
            return;
        }
        if (mb_strlen($fromName) > 120) {
            $this->back('Not saved: the From name is too long (120 characters at most).', '#options');
            return;
        }

        Settings::set('mail_from_name', $fromName);
        Settings::set('mail_from_email', $fromEmail);
        Settings::set('mail_reply_to_approver', isset($_POST['reply_to_approver']) ? '1' : '0');
        $off = [];
        foreach (array_keys(self::NOTICES) as $key) {
            $on = isset($_POST['notify'][$key]);
            Settings::set('notify_' . $key, $on ? '1' : '0');
            if (!$on) {
                $off[] = self::NOTICES[$key][0];
            }
        }
        AuditLogger::log('setting', null, 'email_options', 'Email options saved · From: ' . Settings::mailFromName() . ' <' . Settings::mailFromEmail() . '>'
            . ($off ? ' · turned off: ' . implode(', ', $off) : ''));
        $this->back('Email options saved.', '#options');
    }

    /** Send one test message to an address, ignoring test mode. */
    public function sendTest(): void
    {
        $user = $this->requireRole(['admin']);
        $this->requireCsrf();

        $to = trim((string) ($_POST['test_to'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->back('Enter one valid address to send the test to.', '#send-test');
            return;
        }
        $modeLabel = ['' => 'off (sending normally)', 'redirect' => 'on — redirecting to the test address', 'off' => 'on — not sending'][Settings::emailMode()];
        $ok = $this->mail->send('test_message', $to, 'Test email from the Expenditure & Encumbrance Request System', [
            'sent_by'    => $user['name'],
            'sent_at'    => date('M j, Y g:i A'),
            'from_line'  => Settings::mailFromName() . ' <' . Settings::mailFromEmail() . '>',
            'mode_label' => $modeLabel,
            'cta_url'    => rtrim((string) Config::get('app.base_url'), '/') . '/requests',
        ], null, bypassTestMode: true);
        AuditLogger::log('setting', null, 'email_test', "Test email sent to {$to}" . ($ok ? '' : ' (failed)'));
        $this->back($ok
            ? "Test email sent to {$to}. It goes out even while test mode is on, since you typed the address. Check the inbox and the spam folder."
            : "The test email to {$to} failed — see Recent email below for the error.", '#send-test');
    }

    /** Parses a comma list; flashes and redirects (returning null) if any entry is invalid. */
    private function addressList(string $input): ?array
    {
        $raw = array_values(array_filter(array_map('trim', explode(',', $input))));
        $bad = array_filter($raw, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) === false);
        if ($bad) {
            $this->back('Not saved: “' . implode('”, “', $bad) . '” isn’t a valid email address. Separate several addresses with commas.', '#test-mode');
            return null;
        }
        return $raw;
    }

    private function back(string $message, string $anchor): void
    {
        $this->flash($message);
        $this->redirect('/admin/email' . $anchor);
    }
}

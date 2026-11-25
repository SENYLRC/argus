<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\QuickBooksSync;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Config;
use App\Support\FiscalYear;
use App\Support\QuickBooksClient;
use App\Support\Settings;

/**
 * Administration > QuickBooks: connect QuickBooks Online, choose the budget
 * for a fiscal year, preview the changes, then apply them. Admin only; every
 * connect / disconnect / sync is audited.
 */
final class AdminQuickBooksController extends Controller
{
    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** QuickBooks page: connection status, sync settings and, when $preview is given, the planned changes. */
    public function index(?array $preview = null, ?string $error = null): void
    {
        $this->requireRole(['admin']);
        $fy = (string) ($_POST['fiscal_year'] ?? $_GET['fy'] ?? FiscalYear::current());
        if (!FiscalYear::isValid($fy)) {
            $fy = FiscalYear::current();
        }
        $budgets = [];
        $budgetError = null;
        if (QuickBooksClient::configured() && QuickBooksClient::isConnected()) {
            try {
                $budgets = QuickBooksSync::budgets();
            } catch (\RuntimeException $e) {
                $budgetError = $e->getMessage();
            }
        }
        $this->render('admin/quickbooks.twig', [
            'admin_section' => 'quickbooks',
            'configured' => QuickBooksClient::configured(),
            'status' => QuickBooksClient::status(),
            'redirect_uri' => Config::get('quickbooks.redirect_uri'),
            'fy' => $fy,
            'fiscal_year_choices' => FiscalYear::choices(),
            'budgets' => $budgets,
            'budget_error' => $budgetError,
            'selected_budget' => (string) ($_POST['budget_id'] ?? QuickBooksSync::defaultBudgetId($fy, $budgets) ?? ''),
            'series' => Settings::get('qbo_series', '83,84,85'),
            'auto_sync' => Settings::get('qbo_auto_sync') === '1',
            'last_sync' => Settings::get('qbo_last_sync'),
            'preview' => $preview,
            'error' => $error,
        ]);
    }

    /** POST — off to Intuit to sign in and approve access. */
    public function connect(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        if (!QuickBooksClient::configured()) {
            $this->flash('Add the QuickBooks keys to .env first (see the steps on this page).');
            $this->redirect('/admin/quickbooks');
            return;
        }
        $_SESSION['qbo_state'] = bin2hex(random_bytes(16));
        $this->redirect(QuickBooksClient::authorizeUrl($_SESSION['qbo_state']));
    }

    /** GET — Intuit sends the admin back here with a one-time code. */
    public function callback(): void
    {
        $user = $this->requireRole(['admin']);
        $state = (string) ($_GET['state'] ?? '');
        $expected = (string) ($_SESSION['qbo_state'] ?? '');
        unset($_SESSION['qbo_state']);
        if ($expected === '' || !hash_equals($expected, $state)) {
            $this->flash('QuickBooks connection refused: the request didn’t match (it may have expired). Try Connect again.');
            $this->redirect('/admin/quickbooks');
            return;
        }
        if (isset($_GET['error'])) {
            $this->flash('QuickBooks connection was cancelled.');
            $this->redirect('/admin/quickbooks');
            return;
        }
        try {
            QuickBooksClient::exchangeCode((string) ($_GET['code'] ?? ''), (string) ($_GET['realmId'] ?? ''), $user['email']);
            $company = QuickBooksClient::companyName();
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage());
            $this->redirect('/admin/quickbooks');
            return;
        }
        AuditLogger::log('setting', null, 'qbo_connect', "Connected QuickBooks Online company “{$company}”");
        $this->flash("Connected to QuickBooks: {$company}.");
        $this->redirect('/admin/quickbooks');
    }

    /** Forget the QuickBooks connection; budget lines already synced are left alone. */
    public function disconnect(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $company = Settings::get('qbo_company_name');
        QuickBooksClient::disconnect();
        AuditLogger::log('setting', null, 'qbo_disconnect', "Disconnected QuickBooks Online" . ($company ? " ({$company})" : ''));
        $this->flash('QuickBooks disconnected. Budget lines already synced stay as they are.');
        $this->redirect('/admin/quickbooks');
    }

    /** Save which account-number series to sync and the nightly-sync switch. */
    public function saveSettings(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $series = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['series'] ?? ''))), fn ($s) => preg_match('/^\d{1,6}$/', $s)));
        Settings::set('qbo_series', implode(',', $series));
        Settings::set('qbo_auto_sync', isset($_POST['auto_sync']) ? '1' : '0');
        AuditLogger::log('setting', null, 'qbo_settings', 'QuickBooks sync settings: series ' . (implode(', ', $series) ?: 'all') . '; nightly sync ' . (isset($_POST['auto_sync']) ? 'on' : 'off'));
        $this->flash('QuickBooks sync settings saved.');
        $this->redirect('/admin/quickbooks');
    }

    /** POST — read QuickBooks and show what would change; writes nothing. */
    public function preview(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        [$fy, $budgetId] = $this->postedTarget();
        try {
            $plan = QuickBooksSync::plan($fy, $budgetId);
        } catch (\RuntimeException $e) {
            $this->index(null, $e->getMessage());
            return;
        }
        $this->index($plan);
    }

    /** POST — re-read QuickBooks (so it's current) and apply the changes. */
    public function apply(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        [$fy, $budgetId] = $this->postedTarget();
        try {
            $plan = QuickBooksSync::plan($fy, $budgetId);
            $applied = QuickBooksSync::apply($plan);
        } catch (\RuntimeException $e) {
            $this->index(null, $e->getMessage());
            return;
        }
        AuditLogger::log('budget_line', null, 'qbo_sync',
            "QuickBooks sync for FY {$fy} from budget “{$plan['budget']['name']}”: {$applied['add']} added, {$applied['update']} updated, {$applied['retire']} retired",
            after: ['fy' => $fy, 'budget' => $plan['budget'], 'counts' => $applied, 'skipped' => $plan['skipped']]);
        $this->flash("Synced FY {$fy} from “{$plan['budget']['name']}”: {$applied['add']} added, {$applied['update']} updated, {$applied['retire']} retired.");
        $this->redirect('/admin/quickbooks?fy=' . rawurlencode($fy));
    }

    /** @return array{0:string,1:string} */
    private function postedTarget(): array
    {
        $fy = (string) ($_POST['fiscal_year'] ?? '');
        if (!FiscalYear::isValid($fy)) {
            $fy = FiscalYear::current();
        }
        return [$fy, (string) ($_POST['budget_id'] ?? '')];
    }
}

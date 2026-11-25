<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\BudgetLineRepository;
use App\Http\Controller;
use App\Support\Db;
use App\Support\GeoLocator;

/** Administration > Overview: recent activity and budget-line management (add, access, budgets, retire/restore, promote). */
final class AdminController extends Controller
{
    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** Overview page: budget lines by fiscal year and series, with assignments and budget figures, plus the 20 most recent audit events. */
    public function index(): void
    {
        $this->requireRole(['admin']);

        $stmt = Db::conn()->query('SELECT * FROM audit_log ORDER BY at DESC, id DESC LIMIT 20');
        $auditLog = array_map(function ($ev) {
            $geo = GeoLocator::describe([
                'city' => $ev['geo_city'], 'region' => $ev['geo_region'], 'country' => $ev['geo_country'], 'isp' => $ev['geo_isp'],
            ]);
            return [
                'when' => date('M j, Y g:i A', strtotime($ev['at'])),
                'who' => $ev['actor_email'],
                'what' => $ev['detail'],
                'ip' => $ev['ip'],
                'geo' => $geo,
            ];
        }, $stmt->fetchAll());

        $assignments = BudgetLineRepository::assignmentsByLine();
        // People whose lines are restricted (staff and Admin / Tech); ED and bookkeeper see all.
        $staff = Db::conn()->query("SELECT id, name, role FROM users WHERE role IN ('staff','admin') AND active = 1 ORDER BY name")->fetchAll();
        $staffNames = array_column($staff, 'name', 'id');
        $usage = BudgetLineRepository::usageByLine();
        $lines = array_map(function ($bl) use ($assignments, $staffNames, $usage) {
            $ids = $assignments[(int) $bl['id']] ?? [];
            $bl['assigned_ids'] = $ids;
            $bl['assigned_names'] = array_values(array_filter(array_map(fn ($i) => $staffNames[$i] ?? null, $ids)));
            // A retired line says what's keeping it (or can be deleted outright).
            $bl['kept_by'] = !(int) $bl['active'] ? BudgetLineRepository::usage((int) $bl['id']) : null;
            $f = BudgetLineRepository::budgetFigures($bl, $usage);
            $bl['fig'] = [
                'budget' => $f['budget'] !== null ? \App\Support\Money::format($f['budget']) : null,
                'budget_input' => $f['budget'] !== null ? number_format($f['budget'] / 100, 2, '.', '') : '',
                'approved' => \App\Support\Money::format($f['approved']),
                'pending' => \App\Support\Money::format($f['pending']),
                'remaining' => $f['remaining'] === null ? null
                    : ($f['remaining'] < 0 ? 'over by ' . \App\Support\Money::format(-$f['remaining']) : \App\Support\Money::format($f['remaining']) . ' left'),
                'over' => $f['remaining'] !== null && $f['remaining'] < 0,
            ];
            return $bl;
        }, BudgetLineRepository::all());
        // Shown by fiscal year, then account series (83, 84, 85 …), then number.
        $byFy = [];
        foreach ($lines as $bl) {
            $byFy[$bl['fiscal_year']][] = $bl;
        }
        krsort($byFy);
        $lineSections = [];
        foreach ($byFy as $fy => $fyLines) {
            usort($fyLines, fn ($a, $b) => [$a['code'], $a['class_name']] <=> [$b['code'], $b['class_name']]);
            $lineSections[] = ['fy' => $fy, 'groups' => BudgetLineRepository::groupBySeries($fyLines, fn ($l) => $l['code'])];
        }

        $this->render('admin.twig', [
            'budget_lines' => $lines,
            'line_sections' => $lineSections,
            'budget_classes' => BudgetLineRepository::classes(),
            'fiscal_year_choices' => \App\Support\FiscalYear::choices(),
            'current_fy' => \App\Support\FiscalYear::current(),
            'staff' => $staff,
            'audit_log' => $auditLog,
            'admin_section' => 'overview',
        ]);
    }

    /**
     * Admin / Tech adds a verified line for a fiscal year, in one or more
     * classes (one row per class — the QuickBooks split), shared with everyone
     * or assigned to chosen people. Adding a class to an account number that
     * already exists keeps whoever already had it.
     */
    public function addBudgetLine(): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $code = trim((string) ($_POST['code'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));
        $shared = ($_POST['audience'] ?? '') === 'shared';
        $fy = (string) ($_POST['fiscal_year'] ?? '');
        if (!\App\Support\FiscalYear::isValid($fy)) {
            $fy = \App\Support\FiscalYear::current();
        }
        $validClassIds = array_map('intval', array_column(BudgetLineRepository::classes(), 'id'));
        $classIds = array_values(array_intersect(array_map('intval', (array) ($_POST['class_ids'] ?? [])), $validClassIds));

        if (!$classIds || $code === '' || $label === '' || mb_strlen($code) > 20 || mb_strlen($label) > 160) {
            $this->flash('Not added: tick at least one budget class and enter an account number (up to 20 characters) and a description.');
            $this->redirect('/admin#budget-lines');
            return;
        }

        // Anyone who already has this account number (in another class) keeps it.
        $existingUsers = [];
        $existingShared = false;
        foreach (BudgetLineRepository::all() as $bl) {
            if ($bl['code'] === $code && $bl['fiscal_year'] === $fy) {
                $existingUsers = array_merge($existingUsers, BudgetLineRepository::assignmentsByLine()[(int) $bl['id']] ?? []);
                $existingShared = $existingShared || !empty($bl['shared']);
            }
        }
        $shared = $shared || $existingShared;
        $users = $shared ? [] : array_values(array_unique(array_merge(array_map('intval', (array) ($_POST['users'] ?? [])), $existingUsers)));

        $created = [];
        $skipped = [];
        $classNames = array_column(BudgetLineRepository::classes(), 'name', 'id');
        foreach ($classIds as $classId) {
            $id = BudgetLineRepository::createVerified($classId, $code, $label, $shared, $fy);
            if ($id === null) {
                $skipped[] = $classNames[$classId] ?? (string) $classId;
                continue;
            }
            // Optional budgeted amount for this class (blank = none yet).
            $amount = trim((string) ($_POST['amounts'][$classId] ?? ''));
            if ($amount !== '') {
                BudgetLineRepository::setBudget($id, max(0, \App\Support\Money::toCents($amount)));
            }
            if ($users) {
                BudgetLineRepository::setLineUsers($id, $users);
            }
            $created[] = $classNames[$classId] ?? (string) $classId;
            AuditLogger::log('budget_line', $id, 'create', "Added budget line {$code} · {$label} (FY {$fy}, " . ($classNames[$classId] ?? '') . ')'
                . ($shared ? ' · shared with everyone' : ' · assigned to ' . count($users) . ' people'),
                after: ['class_id' => $classId, 'code' => $code, 'label' => $label, 'fiscal_year' => $fy, 'shared' => $shared, 'user_ids' => $users]);
        }

        if (!$created) {
            $this->flash("Not added: {$code} already exists for FY {$fy} in " . implode(', ', $skipped) . '.');
        } else {
            $this->flash("Added {$code} · {$label} for FY {$fy} in " . implode(', ', $created)
                . ($skipped ? ' (already existed in ' . implode(', ', $skipped) . ')' : '')
                . ($shared ? ' — shared with everyone.' : ($users ? ' — assigned to ' . count($users) . ' people.' : '. Assign it or share it so restricted people can use it.')));
        }
        $this->redirect('/admin#budget-lines');
    }

    /**
     * Who can use a budget line: shared with everyone, or chosen staff /
     * Admin-Tech people. Applies to the account number in every class.
     */
    public function saveBudgetLineAccess(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $id = (int) $params['id'];
        $line = BudgetLineRepository::find($id);
        if (!$line) {
            $this->flash('That budget line no longer exists.');
            $this->redirect('/admin#budget-lines');
            return;
        }
        $siblings = BudgetLineRepository::siblingIds($id);
        $byLine = BudgetLineRepository::assignmentsByLine();
        $before = ['shared' => (bool) $line['shared'], 'user_ids' => $byLine[$id] ?? [], 'rows' => $siblings];
        $shared = ($_POST['audience'] ?? '') === 'shared';
        $users = array_map('intval', (array) ($_POST['users'] ?? []));
        foreach ($siblings as $sid) {
            BudgetLineRepository::setShared($sid, $shared);
            BudgetLineRepository::setLineUsers($sid, $users);
        }
        $after = ['shared' => $shared, 'user_ids' => BudgetLineRepository::assignmentsByLine()[$id] ?? [], 'rows' => $siblings];
        AuditLogger::log('budget_line', $id, 'access', "Changed who can use {$line['code']} · {$line['label']} (FY {$line['fiscal_year']}, all classes): "
            . ($shared ? 'shared with everyone' : count($after['user_ids']) . ' people assigned'), before: $before, after: $after);
        $this->flash("Saved who can use {$line['code']} — applies in every class it's budgeted in.");
        $this->redirect('/admin#line-' . $id);
    }

    /** Set or clear one line row's (class's) budgeted amount for its fiscal year. */
    public function saveBudgetLineBudget(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $line = BudgetLineRepository::find((int) $params['id']);
        if (!$line) {
            $this->flash('That budget line no longer exists.');
            $this->redirect('/admin#budget-lines');
            return;
        }
        $raw = trim((string) ($_POST['budget'] ?? ''));
        $cents = $raw === '' ? null : max(0, \App\Support\Money::toCents($raw));
        BudgetLineRepository::setBudget((int) $line['id'], $cents);
        AuditLogger::log('budget_line', (int) $line['id'], 'budget',
            "Set FY {$line['fiscal_year']} budget for {$line['code']} · {$line['label']} to " . ($cents === null ? 'none' : \App\Support\Money::format($cents)),
            before: ['budget_cents' => $line['budget_cents'] ?? null], after: ['budget_cents' => $cents]);
        $this->flash("Budget for {$line['code']} saved.");
        $this->redirect('/admin#line-' . (int) $line['id']);
    }

    /** Delete an unused line, or retire one that records still reference. */
    public function removeBudgetLine(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $line = BudgetLineRepository::find((int) $params['id']);
        if (!$line) {
            $this->flash('That budget line no longer exists.');
            $this->redirect('/admin#budget-lines');
            return;
        }
        $result = BudgetLineRepository::remove((int) $line['id']);
        AuditLogger::log('budget_line', (int) $line['id'], $result === 'deleted' ? 'delete' : 'retire',
            ($result === 'deleted' ? 'Deleted' : 'Retired') . " budget line {$line['code']} · {$line['label']}", before: $line);
        $this->flash($result === 'deleted'
            ? "Deleted {$line['code']} · {$line['label']}."
            : "Retired {$line['code']} · {$line['label']}: it's gone from the forms, but kept because past requests or recurring lines use it. You can restore it below.");
        $this->redirect('/admin#budget-lines');
    }

    /** Bring a retired budget line back. */
    public function restoreBudgetLine(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $line = BudgetLineRepository::find((int) $params['id']);
        if ($line) {
            BudgetLineRepository::restore((int) $line['id']);
            AuditLogger::log('budget_line', (int) $line['id'], 'restore', "Restored budget line {$line['code']} · {$line['label']}");
            $this->flash("Restored {$line['code']} · {$line['label']}.");
        }
        $this->redirect('/admin#line-' . (int) $params['id']);
    }

    /** Verify a staff free-text line so it joins the dropdown. */
    public function promoteBudgetLine(array $params): void
    {
        $this->requireRole(['admin']);
        $this->requireCsrf();
        $id = (int) $params['id'];
        BudgetLineRepository::promote($id);
        AuditLogger::log('budget_line', $id, 'promote', "Promoted budget line #{$id} to verified");
        $this->flash('Budget line promoted — it now appears in the request form dropdown.');
        $this->redirect('/admin');
    }

    /** Public conformance statement at /accessibility. */
    public function accessibility(): void
    {
        $this->render('accessibility.twig');
    }
}

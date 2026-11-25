<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use App\Support\FiscalYear;
use App\Support\Money;
use App\Support\QuickBooksClient;
use App\Support\Settings;

/**
 * One-way sync QuickBooks Online → budget_lines for one fiscal year:
 * a chosen QuickBooks budget supplies, per account and class, the budgeted
 * amount; the chart of accounts supplies number and name.
 *
 *   plan()  — read QuickBooks and work out the changes (nothing is written)
 *   apply() — write a plan: add / update / retire lines, audited
 *
 * Matching is by class + account number + FY, so assignments and existing
 * requests stay attached. Only lines that came from QuickBooks
 * (qbo_account_id set) are ever retired; lines entered by hand are left
 * alone. Accounts outside the configured series (default 83, 84, 85) and
 * budget amounts with no class are skipped and reported.
 */
final class QuickBooksSync
{
    /** Budgets in QuickBooks, newest first, for the "which budget" picker. */
    public static function budgets(): array
    {
        $out = [];
        foreach (QuickBooksClient::query('SELECT * FROM Budget', 'Budget') as $b) {
            $out[] = [
                'id' => (string) $b['Id'],
                'name' => (string) ($b['Name'] ?? 'Budget ' . $b['Id']),
                'start' => (string) ($b['StartDate'] ?? ''),
                'end' => (string) ($b['EndDate'] ?? ''),
                'active' => (bool) ($b['Active'] ?? true),
                'fy' => !empty($b['StartDate']) ? FiscalYear::forDate($b['StartDate']) : null,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['start'], $a['start']));
        return $out;
    }

    /** The budget saved for a FY, else the active one starting that FY's July 1. */
    public static function defaultBudgetId(string $fy, array $budgets): ?string
    {
        $saved = Settings::get('qbo_budget_' . $fy);
        foreach ($budgets as $b) {
            if ($b['id'] === $saved) {
                return $saved;
            }
        }
        foreach ($budgets as $b) {
            if ($b['active'] && $b['fy'] === $fy) {
                return $b['id'];
            }
        }
        return null;
    }

    /**
     * Read QuickBooks and work out what would change. Nothing is written.
     * @return array{fy:string, budget:array, changes:array, skipped:array, counts:array}
     */
    public static function plan(string $fy, string $budgetId): array
    {
        $budgetRows = QuickBooksClient::query("SELECT * FROM Budget WHERE Id = '" . self::quoteId($budgetId) . "'", 'Budget');
        if (!$budgetRows) {
            throw new \RuntimeException('That QuickBooks budget no longer exists.');
        }
        $classes = [];
        foreach (QuickBooksClient::query('SELECT * FROM Class WHERE Active IN (true, false)', 'Class') as $c) {
            $classes[(string) $c['Id']] = (string) $c['Name'];
        }
        $accounts = [];
        foreach (QuickBooksClient::query('SELECT * FROM Account WHERE Active IN (true, false)', 'Account') as $a) {
            $accounts[(string) $a['Id']] = $a;
        }
        return self::buildPlan($fy, $budgetRows[0], $accounts, $classes, self::existingLines($fy), self::seriesFilter());
    }

    /**
     * Pure planning step (no I/O) — kept separate so it can be tested with
     * sample data. $existing: budget_lines rows for the FY with class_name.
     */
    public static function buildPlan(string $fy, array $budget, array $accounts, array $classes, array $existing, array $series): array
    {
        $skipped = ['outside_series' => [], 'no_class' => 0, 'no_number' => []];
        // Sum the budget per account + class (monthly/quarterly entries add up to the FY).
        $wanted = [];
        foreach ($budget['BudgetDetail'] ?? [] as $d) {
            $acctId = (string) ($d['AccountRef']['value'] ?? '');
            $amount = (int) round(((float) ($d['Amount'] ?? 0)) * 100);
            if ($acctId === '' || $amount === 0) {
                continue;
            }
            $classId = (string) ($d['ClassRef']['value'] ?? '');
            if ($classId === '') {
                $skipped['no_class']++;
                continue;
            }
            $acct = $accounts[$acctId] ?? null;
            [$code, $label] = $acct ? self::numberAndName($acct) : ['', ''];
            if ($code === '') {
                $skipped['no_number'][$acct['Name'] ?? $acctId] = true;
                continue;
            }
            if ($series && !in_array(BudgetLineRepository::series($code), $series, true)) {
                $skipped['outside_series'][$code] = true;
                continue;
            }
            $className = $classes[$classId] ?? ($d['ClassRef']['name'] ?? $classId);
            $key = mb_strtolower($className) . '|' . $code;
            $wanted[$key] ??= ['code' => $code, 'label' => $label, 'class' => $className, 'qbo_class_id' => $classId,
                               'qbo_account_id' => $acctId, 'budget' => 0];
            $wanted[$key]['budget'] += $amount;
        }

        $byKey = [];
        foreach ($existing as $row) {
            $byKey[mb_strtolower($row['class_name']) . '|' . $row['code']] = $row;
        }

        $changes = [];
        foreach ($wanted as $key => $w) {
            $row = $byKey[$key] ?? null;
            if (!$row) {
                $changes[] = ['type' => 'add'] + $w + ['old_budget' => null, 'old_label' => null, 'id' => null];
                continue;
            }
            $diff = [];
            if ($row['label'] !== $w['label']) { $diff[] = 'name'; }
            if ((int) ($row['budget_cents'] ?? -1) !== $w['budget']) { $diff[] = 'budget'; }
            if (!(int) $row['active']) { $diff[] = 'reactivate'; }
            if ((string) ($row['qbo_account_id'] ?? '') !== $w['qbo_account_id']) { $diff[] = 'link'; }
            $changes[] = ['type' => $diff ? 'update' : 'same', 'diff' => $diff, 'id' => (int) $row['id'],
                          'old_budget' => $row['budget_cents'] !== null ? (int) $row['budget_cents'] : null, 'old_label' => $row['label']] + $w;
        }
        // Lines that came from QuickBooks before but aren't in this budget any more.
        foreach ($byKey as $key => $row) {
            if (!isset($wanted[$key]) && !empty($row['qbo_account_id']) && (int) $row['active']) {
                $changes[] = ['type' => 'retire', 'id' => (int) $row['id'], 'code' => $row['code'], 'label' => $row['label'],
                              'class' => $row['class_name'], 'budget' => null, 'old_budget' => $row['budget_cents'] !== null ? (int) $row['budget_cents'] : null];
            }
        }
        usort($changes, fn ($a, $b) => [$a['code'], $a['class']] <=> [$b['code'], $b['class']]);

        $counts = ['add' => 0, 'update' => 0, 'retire' => 0, 'same' => 0];
        foreach ($changes as $c) {
            $counts[$c['type']]++;
        }
        return [
            'fy' => $fy,
            'budget' => ['id' => (string) ($budget['Id'] ?? ''), 'name' => (string) ($budget['Name'] ?? '')],
            'changes' => $changes,
            'skipped' => [
                'outside_series' => array_keys($skipped['outside_series']),
                'no_class' => $skipped['no_class'],
                'no_number' => array_keys($skipped['no_number']),
            ],
            'counts' => $counts,
            'total_budget' => array_sum(array_column(array_filter($changes, fn ($c) => $c['type'] !== 'retire'), 'budget')),
        ];
    }

    /** Write a plan from plan(). Returns the counts actually applied. */
    public static function apply(array $plan): array
    {
        $fy = $plan['fy'];
        $applied = ['add' => 0, 'update' => 0, 'retire' => 0];
        Db::transaction(function (\PDO $pdo) use ($plan, $fy, &$applied) {
            foreach ($plan['changes'] as $c) {
                if ($c['type'] === 'same') {
                    continue;
                }
                if ($c['type'] === 'retire') {
                    $pdo->prepare('UPDATE budget_lines SET active = 0 WHERE id = :id')->execute(['id' => $c['id']]);
                    $applied['retire']++;
                    continue;
                }
                $classId = self::localClassId($pdo, $c['class'], $c['qbo_class_id']);
                if ($c['type'] === 'update') {
                    $pdo->prepare(
                        'UPDATE budget_lines SET label = :l, budget_cents = :b, qbo_account_id = :q, active = 1, verified = 1 WHERE id = :id'
                    )->execute(['l' => mb_substr($c['label'], 0, 160), 'b' => $c['budget'], 'q' => $c['qbo_account_id'], 'id' => $c['id']]);
                    $applied['update']++;
                    continue;
                }
                // add — a new class row of an account number people may already have:
                // inherit "shared" and assignments from its other classes this FY.
                $sib = $pdo->prepare('SELECT id, shared FROM budget_lines WHERE code = :c AND fiscal_year = :fy');
                $sib->execute(['c' => $c['code'], 'fy' => $fy]);
                $siblings = $sib->fetchAll();
                $shared = (int) array_reduce($siblings, fn ($carry, $s) => $carry || (int) $s['shared'], false);
                $pdo->prepare(
                    'INSERT INTO budget_lines (class_id, code, label, budget_cents, qbo_account_id, fiscal_year, verified, active, shared)
                     VALUES (:cl, :c, :l, :b, :q, :fy, 1, 1, :s)'
                )->execute(['cl' => $classId, 'c' => mb_substr($c['code'], 0, 20), 'l' => mb_substr($c['label'], 0, 160),
                            'b' => $c['budget'], 'q' => $c['qbo_account_id'], 'fy' => $fy, 's' => $shared]);
                $newId = (int) $pdo->lastInsertId();
                if ($siblings) {
                    $pdo->prepare(
                        'INSERT IGNORE INTO user_budget_lines (user_id, budget_line_id)
                         SELECT DISTINCT user_id, :new FROM user_budget_lines WHERE budget_line_id IN (' . implode(',', array_map('intval', array_column($siblings, 'id'))) . ')'
                    )->execute(['new' => $newId]);
                }
                $applied['add']++;
            }
        });
        Settings::set('qbo_budget_' . $fy, $plan['budget']['id']);
        Settings::set('qbo_last_sync', date('Y-m-d H:i:s') . " · FY {$fy} · {$applied['add']} added, {$applied['update']} updated, {$applied['retire']} retired");
        return $applied;
    }

    /** "84.32" + "Internet" from an Account: AcctNum when QuickBooks has account numbers on, else a leading number in the name. */
    public static function numberAndName(array $acct): array
    {
        $name = trim((string) ($acct['Name'] ?? ''));
        $num = trim((string) ($acct['AcctNum'] ?? ''));
        if (preg_match('/^(\d+(?:\.\d+)*)\s*[-·:]?\s+(.+)$/u', $name, $m)) {
            // Name itself starts with the number: "84.32 Internet".
            return [$num !== '' ? $num : $m[1], $m[2]];
        }
        return [$num, $name];
    }

    /** Account series to import, from the setting ("83,84,85"). Empty = all. */
    public static function seriesFilter(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', Settings::get('qbo_series', '83,84,85'))), fn ($s) => $s !== ''));
    }

    private static function existingLines(string $fy): array
    {
        $stmt = Db::conn()->prepare(
            'SELECT bl.*, bc.name AS class_name FROM budget_lines bl JOIN budget_classes bc ON bc.id = bl.class_id WHERE bl.fiscal_year = :fy'
        );
        $stmt->execute(['fy' => $fy]);
        return $stmt->fetchAll();
    }

    /** Local class for a QuickBooks class: by QBO id, then by name; created if new. */
    private static function localClassId(\PDO $pdo, string $name, string $qboId): int
    {
        $stmt = $pdo->prepare('SELECT id FROM budget_classes WHERE qbo_class_id = :q');
        $stmt->execute(['q' => $qboId]);
        if ($id = $stmt->fetchColumn()) {
            return (int) $id;
        }
        $stmt = $pdo->prepare('SELECT id FROM budget_classes WHERE LOWER(name) = LOWER(:n)');
        $stmt->execute(['n' => $name]);
        if ($id = $stmt->fetchColumn()) {
            $pdo->prepare('UPDATE budget_classes SET qbo_class_id = :q WHERE id = :id')->execute(['q' => $qboId, 'id' => $id]);
            return (int) $id;
        }
        $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM budget_classes')->fetchColumn();
        $pdo->prepare('INSERT INTO budget_classes (name, sort, qbo_class_id) VALUES (:n, :s, :q)')
            ->execute(['n' => mb_substr($name, 0, 60), 's' => $sort, 'q' => $qboId]);
        return (int) $pdo->lastInsertId();
    }

    /** QuickBooks ids are numeric strings; strip anything else before it goes in a query. */
    private static function quoteId(string $id): string
    {
        return preg_replace('/[^0-9A-Za-z\-]/', '', $id) ?? '';
    }

    /** Money for the preview screen. */
    public static function fmt(?int $cents): string
    {
        return $cents === null ? '—' : Money::format($cents);
    }
}

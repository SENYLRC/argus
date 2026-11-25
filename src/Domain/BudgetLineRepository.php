<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * Budget classes and lines: lookup, per-person visibility (assigned vs shared), amounts and the
 * admin curation of unverified free-text lines.
 */
final class BudgetLineRepository
{
    /** All budget classes in display order. */
    public static function classes(): array
    {
        return Db::conn()->query('SELECT * FROM budget_classes ORDER BY sort')->fetchAll();
    }

    /**
     * Active, verified lines for a class — what the "Account number" select
     * offers — optionally only those for one fiscal year ("2026-27").
     */
    public static function forClass(int $classId, ?string $fiscalYear = null): array
    {
        $sql = 'SELECT * FROM budget_lines WHERE class_id = :c AND active = 1 AND verified = 1';
        $params = ['c' => $classId];
        if ($fiscalYear !== null) {
            $sql .= ' AND fiscal_year = :fy';
            $params['fy'] = $fiscalYear;
        }
        $stmt = Db::conn()->prepare($sql . ' ORDER BY code');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Fiscal years that have at least one active line, newest first. */
    public static function fiscalYears(): array
    {
        return Db::conn()->query('SELECT DISTINCT fiscal_year FROM budget_lines WHERE active = 1 ORDER BY fiscal_year DESC')->fetchAll(\PDO::FETCH_COLUMN);
    }

    // ── Per-person visibility (db/migrations/003) ────────────────────────────

    /** Roles that only see their own lines (plus shared ones). ED and bookkeeper see everything. */
    public const RESTRICTED_ROLES = ['staff', 'admin'];

    /** IDs of the lines assigned to a user (empty = none assigned). */
    public static function assignedIds(int $userId): array
    {
        try {
            $stmt = Db::conn()->prepare('SELECT budget_line_id FROM user_budget_lines WHERE user_id = :u');
            $stmt->execute(['u' => $userId]);
            return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\PDOException) {
            return []; // migration not applied yet — behave as "sees all"
        }
    }

    /**
     * The Executive Director and bookkeeper see every line. Staff and Admin /
     * Tech see their assigned lines plus shared ones — unless nothing is
     * assigned to them yet, in which case they see every line (transitional,
     * so nobody is locked out while assignments are set up).
     */
    public static function seesAll(array $user): bool
    {
        return !in_array($user['role'] ?? 'staff', self::RESTRICTED_ROLES, true) || !self::assignedIds((int) $user['id']);
    }

    /** forClass(), narrowed to what $user may use: their assigned lines plus every shared line. */
    public static function forClassVisibleTo(int $classId, array $user, ?string $fiscalYear = null): array
    {
        $lines = self::forClass($classId, $fiscalYear);
        if (self::seesAll($user)) {
            return $lines;
        }
        $allowed = array_flip(self::assignedIds((int) $user['id']));
        return array_values(array_filter($lines, fn ($l) => !empty($l['shared']) || isset($allowed[(int) $l['id']])));
    }

    /** Whether this user may put the line on a request: they see everything, it is assigned to them, or it is shared. */
    public static function isVisibleTo(int $lineId, array $user): bool
    {
        if (self::seesAll($user) || in_array($lineId, self::assignedIds((int) $user['id']), true)) {
            return true;
        }
        $line = self::find($lineId);
        return $line !== null && !empty($line['shared']);
    }

    /** Shared with everyone on/off (Administration > Budget lines). */
    public static function setShared(int $id, bool $shared): void
    {
        Db::conn()->prepare('UPDATE budget_lines SET shared = :s WHERE id = :id')->execute(['s' => $shared ? 1 : 0, 'id' => $id]);
    }

    /** Replace a user's assignments with these verified, active line ids. */
    public static function setAssignments(int $userId, array $lineIds): void
    {
        $valid = array_column(
            Db::conn()->query('SELECT id FROM budget_lines WHERE active = 1 AND verified = 1')->fetchAll(),
            'id'
        );
        $ids = array_values(array_intersect(array_map('intval', $lineIds), array_map('intval', $valid)));
        Db::transaction(function (\PDO $pdo) use ($userId, $ids) {
            $pdo->prepare('DELETE FROM user_budget_lines WHERE user_id = :u')->execute(['u' => $userId]);
            $ins = $pdo->prepare('INSERT INTO user_budget_lines (user_id, budget_line_id) VALUES (:u, :b)');
            foreach ($ids as $id) {
                $ins->execute(['u' => $userId, 'b' => $id]);
            }
        });
    }

    /** Give one more line to a user who already has assignments (e.g. their own new free-text line). */
    public static function assignIfRestricted(int $userId, int $lineId): void
    {
        if (self::assignedIds($userId)) {
            Db::conn()->prepare('INSERT IGNORE INTO user_budget_lines (user_id, budget_line_id) VALUES (:u, :b)')
                ->execute(['u' => $userId, 'b' => $lineId]);
        }
    }

    // ── Grouping by account number series ────────────────────────────────────
    //
    // Lines are shown in groups by the number before the dot: 83.x, 84.x,
    // 85.x … A new series (e.g. 83) gets its own group automatically.

    /** "84" for "84.32"; the whole code if there's no dot. */
    public static function series(string $code): string
    {
        $dot = strpos($code, '.');
        return $dot === false ? $code : substr($code, 0, $dot);
    }

    /**
     * Split a list into series groups, in series order (83, 84, 85 …), keeping
     * each group's existing order.
     * @param callable(array):string $codeOf
     * @return array<int, array{series:string, items:array}>
     */
    public static function groupBySeries(array $items, callable $codeOf): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[self::series((string) $codeOf($item))][] = $item;
        }
        uksort($groups, fn ($a, $b) => (is_numeric($a) && is_numeric($b)) ? ((int) $a <=> (int) $b) : strcmp((string) $a, (string) $b));
        $out = [];
        foreach ($groups as $series => $list) {
            $out[] = ['series' => (string) $series, 'items' => $list];
        }
        return $out;
    }

    // ── Budget vs. requested ─────────────────────────────────────────────────

    /**
     * Amounts already requested against each line row: approved (incl. older
     * 'complete') and pending, in cents. Rejected requests don't count.
     * $excludeRequestId leaves out the request being edited.
     * @return array<int, array{approved:int, pending:int}>
     */
    public static function usageByLine(?int $excludeRequestId = null): array
    {
        $sql = "SELECT rl.budget_line_id AS id,
                       SUM(CASE WHEN r.status IN ('approved','complete') THEN rl.amount_cents ELSE 0 END) AS approved,
                       SUM(CASE WHEN r.status = 'pending' THEN rl.amount_cents ELSE 0 END) AS pending
                  FROM request_lines rl JOIN requests r ON r.id = rl.request_id
                 WHERE rl.budget_line_id IS NOT NULL AND r.status IN ('approved','complete','pending')";
        $params = [];
        if ($excludeRequestId !== null) {
            $sql .= ' AND r.id <> :ex';
            $params['ex'] = $excludeRequestId;
        }
        $stmt = Db::conn()->prepare($sql . ' GROUP BY rl.budget_line_id');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = ['approved' => (int) $r['approved'], 'pending' => (int) $r['pending']];
        }
        return $out;
    }

    /**
     * Budget figures for one line row: budget (null if none entered),
     * approved, pending, remaining (null without a budget).
     * @return array{budget:?int, approved:int, pending:int, remaining:?int}
     */
    public static function budgetFigures(array $line, array $usage): array
    {
        $u = $usage[(int) $line['id']] ?? ['approved' => 0, 'pending' => 0];
        $budget = isset($line['budget_cents']) ? (int) $line['budget_cents'] : null; // isset() is false for NULL
        return [
            'budget' => $budget,
            'approved' => $u['approved'],
            'pending' => $u['pending'],
            'remaining' => $budget === null ? null : $budget - $u['approved'] - $u['pending'],
        ];
    }

    /** Set (or clear, with null) a line row's budgeted amount. */
    public static function setBudget(int $id, ?int $cents): void
    {
        Db::conn()->prepare('UPDATE budget_lines SET budget_cents = :c WHERE id = :id')->execute(['c' => $cents, 'id' => $id]);
    }

    // ── Assigning by budget line (account number), not by class ──────────────
    //
    // A line's classes (Operating, State Operating, HLSP, EmpireADC…) are the
    // QuickBooks split and only matter when filling in a request. Assigning a
    // person "84.32 Internet" therefore covers 84.32 in every class it's
    // budgeted in that fiscal year. Keys look like "2026-27|84.32".

    /**
     * One entry per account number per fiscal year (classes collapsed), for
     * the Accounts checkbox list.
     * @return array<int, array{fy:string, lines:array<int, array{key:string, code:string, label:string, shared:bool}>}>
     */
    public static function codesForAssignment(): array
    {
        $rows = Db::conn()->query(
            'SELECT fiscal_year, code, MIN(label) AS label, MIN(shared) AS all_shared
               FROM budget_lines WHERE active = 1 AND verified = 1
           GROUP BY fiscal_year, code
           ORDER BY fiscal_year DESC, code'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['fiscal_year']]['fy'] = $r['fiscal_year'];
            $out[$r['fiscal_year']]['lines'][] = [
                'key' => $r['fiscal_year'] . '|' . $r['code'], 'code' => $r['code'], 'label' => $r['label'],
                'shared' => (bool) $r['all_shared'],
            ];
        }
        // Plain text order (from the SQL) is the right order within a series:
        // 85.212, 85.22, 85.220 … 85.235, 85.25. Then split into 83/84/85 groups.
        foreach ($out as &$g) {
            $g['groups'] = self::groupBySeries($g['lines'], fn ($l) => $l['code']);
        }
        unset($g);
        return array_values($out);
    }

    /** "fy|code" keys a user is assigned (any class counts). */
    public static function assignedKeys(int $userId): array
    {
        try {
            $stmt = Db::conn()->prepare(
                "SELECT DISTINCT CONCAT(bl.fiscal_year, '|', bl.code)
                   FROM user_budget_lines ubl JOIN budget_lines bl ON bl.id = ubl.budget_line_id
                  WHERE ubl.user_id = :u"
            );
            $stmt->execute(['u' => $userId]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException) {
            return [];
        }
    }

    /** Replace a user's assignments from "fy|code" keys — every class of each line. */
    public static function setAssignmentsByKeys(int $userId, array $keys): void
    {
        $ids = [];
        $stmt = Db::conn()->prepare('SELECT id FROM budget_lines WHERE fiscal_year = :fy AND code = :code AND active = 1 AND verified = 1');
        foreach (array_unique($keys) as $key) {
            if (!is_string($key) || !str_contains($key, '|')) {
                continue;
            }
            [$fy, $code] = explode('|', $key, 2);
            $stmt->execute(['fy' => $fy, 'code' => $code]);
            $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
        }
        self::setAssignments($userId, $ids);
    }

    /** All rows for the same account number and fiscal year (the line in each of its classes). */
    public static function siblingIds(int $lineId): array
    {
        $stmt = Db::conn()->prepare(
            'SELECT b.id FROM budget_lines a JOIN budget_lines b ON b.code = a.code AND b.fiscal_year = a.fiscal_year WHERE a.id = :id'
        );
        $stmt->execute(['id' => $lineId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Verified, active lines grouped by fiscal year and class — for the Accounts checkbox list. */
    public static function groupedForAssignment(): array
    {
        $groups = [];
        foreach (self::fiscalYears() as $fy) {
            foreach (self::classes() as $c) {
                $lines = self::forClass((int) $c['id'], $fy);
                if ($lines) {
                    $groups[] = ['name' => "FY {$fy} · {$c['name']}", 'lines' => $lines];
                }
            }
        }
        return $groups;
    }

    /** Every line with its class name, newest fiscal year first (admin overview). */
    public static function all(): array
    {
        return Db::conn()->query(
            'SELECT bl.*, bc.name AS class_name FROM budget_lines bl
             JOIN budget_classes bc ON bc.id = bl.class_id
             ORDER BY bl.fiscal_year DESC, bc.sort, bl.code'
        )->fetchAll();
    }

    /** One line by id, or null. */
    public static function find(int $id): ?array
    {
        $stmt = Db::conn()->prepare('SELECT * FROM budget_lines WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Admin-added line: verified straight away. Returns the new id, or null if that class/code/FY already exists. */
    public static function createVerified(int $classId, string $code, string $label, bool $shared, string $fiscalYear): ?int
    {
        $dup = Db::conn()->prepare('SELECT id FROM budget_lines WHERE class_id = :c AND code = :code AND fiscal_year = :fy');
        $dup->execute(['c' => $classId, 'code' => $code, 'fy' => $fiscalYear]);
        if ($dup->fetch()) {
            return null;
        }
        Db::conn()->prepare(
            'INSERT INTO budget_lines (class_id, code, label, fiscal_year, verified, active, shared) VALUES (:c, :code, :label, :fy, 1, 1, :s)'
        )->execute(['c' => $classId, 'code' => $code, 'label' => $label, 'fy' => $fiscalYear, 's' => $shared ? 1 : 0]);
        return (int) Db::conn()->lastInsertId();
    }

    /** User ids assigned to each line: [lineId => [userId, …]]. */
    public static function assignmentsByLine(): array
    {
        $out = [];
        try {
            foreach (Db::conn()->query('SELECT budget_line_id, user_id FROM user_budget_lines')->fetchAll() as $r) {
                $out[(int) $r['budget_line_id']][] = (int) $r['user_id'];
            }
        } catch (\PDOException) {
        }
        return $out;
    }

    /** Replace which people (staff / Admin-Tech) are assigned to one line — the line-side view of user_budget_lines. */
    public static function setLineUsers(int $lineId, array $userIds): void
    {
        $eligible = array_map('intval', Db::conn()->query("SELECT id FROM users WHERE role IN ('staff','admin')")->fetchAll(\PDO::FETCH_COLUMN));
        $ids = array_values(array_intersect(array_map('intval', $userIds), $eligible));
        Db::transaction(function (\PDO $pdo) use ($lineId, $ids) {
            $pdo->prepare(
                "DELETE ubl FROM user_budget_lines ubl JOIN users u ON u.id = ubl.user_id
                  WHERE ubl.budget_line_id = :b AND u.role IN ('staff','admin')"
            )->execute(['b' => $lineId]);
            $ins = $pdo->prepare('INSERT INTO user_budget_lines (user_id, budget_line_id) VALUES (:u, :b)');
            foreach ($ids as $id) {
                $ins->execute(['u' => $id, 'b' => $lineId]);
            }
        });
    }

    /**
     * A staff free-text line ("Other — enter a new line") lands here as unverified so the dropdown stays
     * curated without blocking anyone (build-notes §2); admin later promotes or merges it.
     * Reuses an existing row for the same class/code/FY.
     */
    public static function createUnverified(int $classId, string $code, string $label, string $fiscalYear): int
    {
        // (class, code, FY) is unique: if someone already typed this line in,
        // reuse that row instead of failing on the duplicate.
        $existing = Db::conn()->prepare('SELECT id FROM budget_lines WHERE class_id = :c AND code = :code AND fiscal_year = :fy');
        $existing->execute(['c' => $classId, 'code' => $code, 'fy' => $fiscalYear]);
        if ($id = $existing->fetchColumn()) {
            return (int) $id;
        }
        $stmt = Db::conn()->prepare(
            'INSERT INTO budget_lines (class_id, code, label, fiscal_year, verified) VALUES (:c, :code, :label, :fy, 0)'
        );
        $stmt->execute(['c' => $classId, 'code' => $code, 'label' => $label, 'fy' => $fiscalYear]);
        return (int) Db::conn()->lastInsertId();
    }

    /**
     * Why a line can't be deleted: requests that use it, and *active*
     * recurring lines set up on it. (Removed recurring lines don't count —
     * they're hidden for good; their link is cleared when the line goes.)
     * @return array{requests: array<int, array{number:string, status:string}>, recurring: array<int, array{payable_to:string, owner:string}>}
     */
    public static function usage(int $id): array
    {
        $req = Db::conn()->prepare(
            'SELECT DISTINCT r.number, r.status FROM request_lines rl JOIN requests r ON r.id = rl.request_id
              WHERE rl.budget_line_id = :id ORDER BY r.number'
        );
        $req->execute(['id' => $id]);
        $rec = Db::conn()->prepare(
            'SELECT rt.payable_to, u.name AS owner FROM recurring_templates rt JOIN users u ON u.id = rt.owner_id
              WHERE rt.budget_line_id = :id AND rt.active = 1 ORDER BY rt.payable_to'
        );
        $rec->execute(['id' => $id]);
        return ['requests' => $req->fetchAll(), 'recurring' => $rec->fetchAll()];
    }

    /** True if a request or an active recurring line uses it (so it must be kept for the record). */
    public static function isUsed(int $id): bool
    {
        $u = self::usage($id);
        return $u['requests'] || $u['recurring'];
    }

    /**
     * Remove a line. Not used → deleted outright (its assignments go with it;
     * removed recurring lines that pointed at it are unlinked). Used by a
     * request or an active recurring line → retired instead (active = 0):
     * gone from every form, still shown on the records that reference it.
     * @return 'deleted'|'retired'
     */
    public static function remove(int $id): string
    {
        if (self::isUsed($id)) {
            Db::conn()->prepare('UPDATE budget_lines SET active = 0 WHERE id = :id')->execute(['id' => $id]);
            return 'retired';
        }
        Db::transaction(function (\PDO $pdo) use ($id) {
            $pdo->prepare('UPDATE recurring_templates SET budget_line_id = NULL WHERE budget_line_id = :id AND active = 0')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM budget_lines WHERE id = :id')->execute(['id' => $id]);
        });
        return 'deleted';
    }

    /** Bring a retired line back into the forms. */
    public static function restore(int $id): void
    {
        Db::conn()->prepare('UPDATE budget_lines SET active = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    /** Mark an unverified line as verified so it appears in the dropdown. */
    public static function promote(int $id): void
    {
        $stmt = Db::conn()->prepare('UPDATE budget_lines SET verified = 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Merge an unverified line into an existing verified one: repoint request_lines, drop the duplicate. */
    public static function mergeInto(int $unverifiedId, int $targetId): void
    {
        Db::transaction(function ($pdo) use ($unverifiedId, $targetId) {
            $stmt = $pdo->prepare('UPDATE request_lines SET budget_line_id = :target WHERE budget_line_id = :old');
            $stmt->execute(['target' => $targetId, 'old' => $unverifiedId]);
            $stmt = $pdo->prepare('DELETE FROM budget_lines WHERE id = :id');
            $stmt->execute(['id' => $unverifiedId]);
        });
    }
}

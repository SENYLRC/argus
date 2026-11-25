<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Http\Controller;
use App\Support\Db;
use App\Support\GeoLocator;

/**
 * Administration > Audit log: every recorded action, filterable, with the
 * before/after values for edits, and a CSV export for auditors. The log is
 * append-only for the app's own database user (deploy/install.sh grants only
 * SELECT and INSERT on audit_log), so nothing here can edit or delete it.
 */
final class AdminAuditController extends Controller
{
    private const PER_PAGE = 100;

    private const AREAS = [
        'request' => 'Requests', 'recurring_batch' => 'Monthly batches', 'recurring_template' => 'Recurring lines',
        'user' => 'Accounts', 'auth' => 'Sign-in', 'budget_line' => 'Budget lines', 'setting' => 'Settings',
        'branding' => 'Logos', 'storage' => 'Files & storage', 'system' => 'System (cron)', 'audit' => 'Audit log exports',
    ];

    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'admin';
    }

    /** Paged, filtered audit log (admin only). */
    public function index(): void
    {
        $this->requireRole(['admin']);
        $f = $this->filters();
        [$where, $params] = $this->where($f);

        $count = Db::conn()->prepare("SELECT COUNT(*) FROM audit_log {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

        $stmt = Db::conn()->prepare(
            "SELECT * FROM audit_log {$where} ORDER BY at DESC, id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE)
        );
        $stmt->execute($params);
        $rows = array_map(fn ($ev) => [
            'when'   => date('M j, Y g:i:s A', strtotime($ev['at'])),
            'who'    => $ev['actor_email'],
            'area'   => self::AREAS[$ev['entity']] ?? $ev['entity'],
            'action' => $ev['action'],
            'what'   => $ev['detail'],
            'ip'     => $ev['ip'],
            'geo'    => GeoLocator::describe(['city' => $ev['geo_city'], 'region' => $ev['geo_region'], 'country' => $ev['geo_country'], 'isp' => $ev['geo_isp']]),
            'before' => $this->pretty($ev['before_json']),
            'after'  => $this->pretty($ev['after_json']),
        ], $stmt->fetchAll());

        $people = Db::conn()->query('SELECT DISTINCT actor_email FROM audit_log ORDER BY actor_email')->fetchAll(\PDO::FETCH_COLUMN);
        $qs = http_build_query(array_filter($f, fn ($v) => $v !== ''));

        $this->render('admin/audit.twig', [
            'admin_section' => 'audit',
            'rows'    => $rows,
            'filters' => $f,
            'areas'   => self::AREAS,
            'people'  => $people,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'qs'      => $qs,
        ]);
    }

    /** CSV of the current filter, every column, no paging. The export itself is audited. */
    public function export(): void
    {
        $this->requireRole(['admin']);
        $f = $this->filters();
        [$where, $params] = $this->where($f);
        AuditLogger::log('audit', null, 'export', 'Exported the audit log' . ($where ? ' (filtered: ' . http_build_query(array_filter($f, fn ($v) => $v !== '')) . ')' : ''));

        $stmt = Db::conn()->prepare("SELECT * FROM audit_log {$where} ORDER BY at DESC, id DESC");
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="argus-audit-log-' . date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
        fputcsv($out, ['id', 'time', 'account', 'area', 'action', 'record id', 'detail', 'before', 'after', 'ip', 'city', 'region', 'country', 'isp', 'user agent']);
        while ($ev = $stmt->fetch()) {
            fputcsv($out, array_map([$this, 'csvSafe'], [
                $ev['id'], $ev['at'], $ev['actor_email'], $ev['entity'], $ev['action'], $ev['entity_id'], $ev['detail'],
                $ev['before_json'], $ev['after_json'], $ev['ip'], $ev['geo_city'], $ev['geo_region'], $ev['geo_country'], $ev['geo_isp'], $ev['user_agent'],
            ]));
        }
        fclose($out);
    }

    private function filters(): array
    {
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        return [
            'who'  => mb_substr(trim((string) ($_GET['who'] ?? '')), 0, 190),
            'area' => array_key_exists((string) ($_GET['area'] ?? ''), self::AREAS) ? (string) $_GET['area'] : '',
            'q'    => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'from' => $date($_GET['from'] ?? ''),
            'to'   => $date($_GET['to'] ?? ''),
        ];
    }

    /** @return array{0:string,1:array} */
    private function where(array $f): array
    {
        $clauses = [];
        $params = [];
        if ($f['who'] !== '') { $clauses[] = 'actor_email = :who'; $params['who'] = $f['who']; }
        if ($f['area'] !== '') { $clauses[] = 'entity = :area'; $params['area'] = $f['area']; }
        if ($f['q'] !== '') { $clauses[] = '(detail LIKE :q OR action LIKE :q2 OR ip LIKE :q3)'; $params += ['q' => "%{$f['q']}%", 'q2' => "%{$f['q']}%", 'q3' => "%{$f['q']}%"]; }
        if ($f['from'] !== '') { $clauses[] = 'at >= :from'; $params['from'] = $f['from'] . ' 00:00:00'; }
        if ($f['to'] !== '') { $clauses[] = 'at <= :to'; $params['to'] = $f['to'] . ' 23:59:59'; }
        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    private function pretty(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return $data === null ? $json : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Neutralise spreadsheet formula injection (=, +, -, @ at the start of a cell). */
    private function csvSafe(mixed $v): string
    {
        $s = (string) ($v ?? '');
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $s : $s;
    }
}

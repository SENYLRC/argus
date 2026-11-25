<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use App\Support\Money;
use PDO;

/**
 * Reads and writes requests, their budget lines and attachments. Authorisation (who may see or change
 * a request) is enforced in the controllers and in the WHERE clauses of the *IfEditable/*IfDeletable methods.
 */
final class RequestRepository
{
    public const ROLE_LABELS = [
        'admin'      => 'Admin / Tech',
        'ed'         => 'Executive Director',
        'bookkeeper' => 'Bookkeeper',
        'staff'      => 'Staff',
    ];

    /** Staff see only their own requests; every other role sees everything (build-notes §1). */
    public static function listForRole(?int $requesterId): array
    {
        $sql = "SELECT r.*, u.name AS requester_name FROM requests r
                JOIN users u ON u.id = r.requester_id";
        $params = [];
        if ($requesterId !== null) {
            $sql .= ' WHERE r.requester_id = :me';
            $params['me'] = $requesterId;
        }
        $sql .= ' ORDER BY r.created_at DESC';
        $stmt = Db::conn()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return [];
        }
        $summaries = self::lineSummaries(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['line_summary'] = $summaries[$row['id']] ?? '';
        }
        return $rows;
    }

    private static function lineSummaries(array $requestIds): array
    {
        if (!$requestIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = Db::conn()->prepare(
            "SELECT rl.request_id, COALESCE(bl.code, rl.free_text) AS code
             FROM request_lines rl LEFT JOIN budget_lines bl ON bl.id = rl.budget_line_id
             WHERE rl.request_id IN ($placeholders) ORDER BY rl.sort"
        );
        $stmt->execute($requestIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['request_id']][] = $row['code'];
        }
        foreach ($out as $id => $codes) {
            $out[$id] = implode(' · ', $codes);
        }
        return $out;
    }

    /** One request with requester, lines and attachments attached, or null. */
    public static function find(int $id): ?array
    {
        $stmt = Db::conn()->prepare(
            'SELECT r.*, u.name AS requester_name, u.email AS requester_email
             FROM requests r JOIN users u ON u.id = r.requester_id WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['lines'] = self::linesFor($id);
        $row['attachments'] = self::attachmentsFor($id);
        return $row;
    }

    /** A request's budget lines in order, with line code/label and class name. */
    public static function linesFor(int $requestId): array
    {
        $stmt = Db::conn()->prepare(
            'SELECT rl.*, bl.code AS line_code, bl.label AS line_label, bl.verified AS line_verified, bc.name AS class_name
             FROM request_lines rl
             LEFT JOIN budget_lines bl ON bl.id = rl.budget_line_id
             JOIN budget_classes bc ON bc.id = rl.class_id
             WHERE rl.request_id = :id ORDER BY rl.sort'
        );
        $stmt->execute(['id' => $requestId]);
        return $stmt->fetchAll();
    }

    /** A request's uploaded files. */
    public static function attachmentsFor(int $requestId): array
    {
        $stmt = Db::conn()->prepare('SELECT * FROM attachments WHERE request_id = :id ORDER BY id');
        $stmt->execute(['id' => $requestId]);
        return $stmt->fetchAll();
    }

    /**
     * @param array $data kind, requester_id, payable_to, remit_address, payment_method,
     *                     date_needed, justification, is_recurring, recurring_id, batch_id
     * @param array $lines each: budget_line_id|null, free_text|null, class_id, amount_cents
     */
    public static function create(array $data, array $lines): int
    {
        return Db::transaction(function (PDO $pdo) use ($data, $lines) {
            $number = RequestNumberGenerator::next($pdo);
            $total = array_sum(array_column($lines, 'amount_cents'));

            $stmt = $pdo->prepare(
                'INSERT INTO requests
                    (number, kind, fiscal_year, status, requester_id, payable_to, remit_address, payment_method,
                     date_needed, justification, is_recurring, recurring_id, batch_id, total_cents)
                 VALUES
                    (:number, :kind, :fiscal_year, \'pending\', :requester_id, :payable_to, :remit_address, :payment_method,
                     :date_needed, :justification, :is_recurring, :recurring_id, :batch_id, :total_cents)'
            );
            $stmt->execute([
                'number'         => $number,
                'kind'           => $data['kind'],
                'fiscal_year'    => $data['fiscal_year'] ?? \App\Support\FiscalYear::current(),
                'requester_id'   => $data['requester_id'],
                'payable_to'     => $data['payable_to'],
                'remit_address'  => $data['remit_address'],
                'payment_method' => $data['payment_method'],
                'date_needed'    => $data['date_needed'] ?: null,
                'justification'  => $data['justification'],
                'is_recurring'   => !empty($data['is_recurring']) ? 1 : 0,
                'recurring_id'   => $data['recurring_id'] ?? null,
                'batch_id'       => $data['batch_id'] ?? null,
                'total_cents'    => $total,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::insertLines($pdo, $id, $lines);
            return $id;
        });
    }

    private static function insertLines(PDO $pdo, int $requestId, array $lines): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO request_lines (request_id, budget_line_id, free_text, class_id, amount_cents, sort)
             VALUES (:rid, :bid, :ft, :cid, :amt, :sort)'
        );
        foreach (array_values($lines) as $i => $line) {
            $stmt->execute([
                'rid'  => $requestId,
                'bid'  => $line['budget_line_id'] ?? null,
                'ft'   => $line['free_text'] ?? null,
                'cid'  => $line['class_id'],
                'amt'  => $line['amount_cents'],
                'sort' => $i,
            ]);
        }
    }

    /** Enforced server-side, not just hidden in the UI: WHERE requester_id = :me AND status = 'pending'. */
    public static function updateIfEditable(int $id, int $actorId, bool $isAdmin, array $data, array $lines): bool
    {
        return Db::transaction(function (PDO $pdo) use ($id, $actorId, $isAdmin, $data, $lines) {
            $total = array_sum(array_column($lines, 'amount_cents'));
            $sql = 'UPDATE requests SET kind = :kind, fiscal_year = :fiscal_year, payable_to = :payable_to, remit_address = :remit_address,
                        payment_method = :payment_method, date_needed = :date_needed, justification = :justification,
                        is_recurring = :is_recurring, total_cents = :total_cents
                    WHERE id = :id AND status = \'pending\'';
            $params = [
                'kind'           => $data['kind'],
                'fiscal_year'    => $data['fiscal_year'] ?? \App\Support\FiscalYear::current(),
                'payable_to'     => $data['payable_to'],
                'remit_address'  => $data['remit_address'],
                'payment_method' => $data['payment_method'],
                'date_needed'    => $data['date_needed'] ?: null,
                'justification'  => $data['justification'],
                'is_recurring'   => !empty($data['is_recurring']) ? 1 : 0,
                'total_cents'    => $total,
                'id'             => $id,
            ];
            if (!$isAdmin) {
                $sql .= ' AND requester_id = :requester_id';
                $params['requester_id'] = $actorId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $pdo->prepare('DELETE FROM request_lines WHERE request_id = :id')->execute(['id' => $id]);
            self::insertLines($pdo, $id, $lines);
            return true;
        });
    }

    /** Delete a still-pending request (own request, or any if admin). False if nothing was deleted. */
    public static function deleteIfDeletable(int $id, int $actorId, bool $isAdmin): bool
    {
        $sql = "DELETE FROM requests WHERE id = :id AND status = 'pending'";
        $params = ['id' => $id];
        if (!$isAdmin) {
            $sql .= ' AND requester_id = :requester_id';
            $params['requester_id'] = $actorId;
        }
        $stmt = Db::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** Record an already-stored upload against a request. */
    public static function addAttachment(int $requestId, string $origName, string $storedPath, string $mime, int $bytes, int $uploadedBy, ?string $description = null): void
    {
        $stmt = Db::conn()->prepare(
            'INSERT INTO attachments (request_id, orig_name, stored_path, mime, bytes, description, uploaded_by)
             VALUES (:rid, :name, :path, :mime, :bytes, :desc, :by)'
        );
        $stmt->execute([
            'rid'  => $requestId,
            'name' => $origName,
            'path' => $storedPath,
            'mime' => $mime,
            'bytes'=> $bytes,
            'desc' => $description,
            'by'   => $uploadedBy,
        ]);
    }

    /**
     * "Submitted" plus every signoff, in the shape the detail screen's
     * timeline needs (title/who/when/note/signature/sigFile/dot).
     */
    public static function timeline(array $request): array
    {
        $entries = [[
            'title'     => 'Submitted',
            'who'       => $request['requester_name'],
            'when'      => date('M j, Y g:i A', strtotime($request['created_at'])),
            'note'      => null,
            'signature' => null,
            'sigFile'   => null,
            'dot'       => 'blue',
        ]];

        $stmt = Db::conn()->prepare('SELECT * FROM signoffs WHERE request_id = :id ORDER BY signed_at ASC, id ASC');
        $stmt->execute(['id' => $request['id']]);

        foreach ($stmt->fetchAll() as $s) {
            $who = $s['typed_name'] . ', ' . (self::ROLE_LABELS[$s['role_at_time']] ?? $s['role_at_time']);
            $when = date('M j, Y g:i A', strtotime($s['signed_at']));
            $sigFile = $s['signature_copy_path'] ? basename($s['signature_copy_path']) : null;

            if ($s['action'] === 'approve') {
                $entries[] = [
                    'title' => 'Approved & signed', 'who' => $who, 'when' => $when,
                    'note' => null, 'signature' => $s['typed_name'], 'sigFile' => $sigFile, 'dot' => 'green',
                ];
            } elseif ($s['action'] === 'reject') {
                $entries[] = [
                    'title' => 'Rejected', 'who' => $who, 'when' => $when,
                    'note' => $request['reject_reason'], 'signature' => null, 'sigFile' => null, 'dot' => 'maroon',
                ];
            } else { // complete
                $note = 'Posted to account ' . date('M j, Y', strtotime($request['posted_date'] ?? $s['signed_at']))
                    . ' · ' . ($request['check_number'] ?: '—');
                $entries[] = [
                    'title' => 'Posted & marked complete', 'who' => $who, 'when' => $when,
                    'note' => $note, 'signature' => $s['typed_name'], 'sigFile' => $sigFile, 'dot' => 'green',
                ];
            }
        }

        return $entries;
    }

    /** Remember where the generated PDF record is stored, so it is reused rather than regenerated. */
    public static function setRecordPdfPath(int $id, string $relativePath): void
    {
        $stmt = Db::conn()->prepare('UPDATE requests SET record_pdf_path = :path WHERE id = :id');
        $stmt->execute(['path' => $relativePath, 'id' => $id]);
    }

    /** Staff may only ever act on their own requests, even if an id in a batch selection wasn't theirs. */
    public static function filterVisible(array $ids, ?int $requesterId): array
    {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id FROM requests WHERE id IN ($placeholders)";
        $params = $ids;
        if ($requesterId !== null) {
            $sql .= ' AND requester_id = ?';
            $params[] = $requesterId;
        }
        $stmt = Db::conn()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /** Counts and total value for the list screen's summary strip. */
    public static function stats(array $rows): array
    {
        $pending = array_filter($rows, fn ($r) => $r['status'] === 'pending');
        $approved = array_filter($rows, fn ($r) => in_array($r['status'], ['approved', 'complete'], true));
        $value = array_sum(array_column(array_filter($rows, fn ($r) => $r['status'] !== 'rejected'), 'total_cents'));
        return [
            'pending_count'  => count($pending),
            'approved_count' => count($approved),
            'shown_count'    => count($rows),
            'value_cents'    => $value,
        ];
    }
}

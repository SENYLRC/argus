<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use PDO;

/**
 * Each person's own monthly sheet: their active recurring lines (templates
 * they own), which they set up and maintain themselves from the Monthly
 * batch tab. Submitting creates one 'pending' request per included line,
 * attributed to them (build-notes: "creates one request per included line,
 * all pending"). Changed 2026-10-03 from the original single shared sheet.
 */
final class RecurringBatchService
{
    public const PAYMENT_METHODS = ['card' => 'Card', 'ach' => 'ACH', 'check' => 'Check', 'reimbursement' => 'Reimbursement'];

    private const SELECT = 'SELECT rt.*, bc.name AS class_name, bl.code AS line_code, bl.label AS line_label
             FROM recurring_templates rt
             JOIN budget_classes bc ON bc.id = rt.class_id
             LEFT JOIN budget_lines bl ON bl.id = rt.budget_line_id';

    /** Every active line, all owners (reminder fan-out). */
    public static function activeTemplates(): array
    {
        return Db::conn()->query(self::SELECT . ' WHERE rt.active = 1 ORDER BY rt.owner_id, rt.payable_to')->fetchAll();
    }

    /** One person's active lines — their sheet. */
    public static function templatesFor(int $ownerId): array
    {
        $stmt = Db::conn()->prepare(self::SELECT . ' WHERE rt.active = 1 AND rt.owner_id = :o ORDER BY rt.payable_to');
        $stmt->execute(['o' => $ownerId]);
        return $stmt->fetchAll();
    }

    /** One active recurring line, only if it belongs to $ownerId (null otherwise). */
    public static function findOwned(int $id, int $ownerId): ?array
    {
        $stmt = Db::conn()->prepare(self::SELECT . ' WHERE rt.id = :id AND rt.owner_id = :o AND rt.active = 1');
        $stmt->execute(['id' => $id, 'o' => $ownerId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create (id null) or update one of the owner's lines.
     * @return array<string,string> field => error message; empty on success
     */
    public static function save(int $ownerId, ?int $id, string $payableTo, int $budgetLineId, int $defaultCents, string $paymentMethod): array
    {
        $errors = [];
        $payableTo = trim($payableTo);
        if ($payableTo === '') {
            $errors['payable_to'] = 'Enter who this is paid to.';
        } elseif (mb_strlen($payableTo) > 190) {
            $errors['payable_to'] = 'Keep the payee to 190 characters or fewer.';
        }
        $line = BudgetLineRepository::find($budgetLineId);
        $owner = \App\Domain\UserRepository::find($ownerId);
        if (!$line || !$line['active'] || !$line['verified'] || !$owner || !BudgetLineRepository::isVisibleTo($budgetLineId, $owner)) {
            $errors['budget_line_id'] = 'Choose a budget line.';
        }
        if ($defaultCents <= 0) {
            $errors['default_cents'] = 'Enter the usual monthly amount, more than $0.00.';
        } elseif ($defaultCents > 100000000) {
            $errors['default_cents'] = 'That amount is too large for a recurring line.';
        }
        if (!isset(self::PAYMENT_METHODS[$paymentMethod])) {
            $errors['payment_method'] = 'Choose how it’s paid.';
        }
        if ($id !== null && !self::findOwned($id, $ownerId)) {
            $errors['form'] = 'That recurring line no longer exists.';
        }
        if ($errors) {
            return $errors;
        }

        $params = [
            'payable_to' => $payableTo, 'bid' => $budgetLineId, 'cid' => (int) $line['class_id'],
            'cents' => $defaultCents, 'pm' => $paymentMethod, 'owner' => $ownerId,
        ];
        if ($id === null) {
            Db::conn()->prepare(
                'INSERT INTO recurring_templates (owner_id, payable_to, budget_line_id, class_id, default_cents, last_cents, payment_method, active)
                 VALUES (:owner, :payable_to, :bid, :cid, :cents, 0, :pm, 1)'
            )->execute($params);
        } else {
            Db::conn()->prepare(
                'UPDATE recurring_templates SET payable_to = :payable_to, budget_line_id = :bid, class_id = :cid,
                        default_cents = :cents, payment_method = :pm
                  WHERE id = :id AND owner_id = :owner'
            )->execute($params + ['id' => $id]);
        }
        return [];
    }

    /** Soft-remove: past requests keep pointing at the template (recurring_id). */
    public static function deactivate(int $id, int $ownerId): bool
    {
        $stmt = Db::conn()->prepare('UPDATE recurring_templates SET active = 0 WHERE id = :id AND owner_id = :o AND active = 1');
        $stmt->execute(['id' => $id, 'o' => $ownerId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<int,int> $amountsCentsByTemplateId only templates present here (checked) are submitted
     * @return string[] the newly created request numbers
     */
    public static function submit(int $submitterId, string $period, array $amountsCentsByTemplateId): array
    {
        if (!$amountsCentsByTemplateId) {
            throw new WorkflowException('Nothing selected in the batch.');
        }

        return Db::transaction(function (PDO $pdo) use ($submitterId, $period, $amountsCentsByTemplateId) {
            // UNIQUE (owner_id, period) records that this submitter has run the batch this month;
            // a second attempt in the same period is a no-op rather than a duplicate mailing.
            $exists = $pdo->prepare('SELECT id FROM recurring_batches WHERE owner_id = :owner AND period = :period');
            $exists->execute(['owner' => $submitterId, 'period' => $period]);
            if ($exists->fetch()) {
                throw new WorkflowException("You've already submitted the {$period} batch.");
            }

            $batchStmt = $pdo->prepare(
                'INSERT INTO recurring_batches (owner_id, period, submitted_at) VALUES (:owner, :period, NOW())'
            );
            $batchStmt->execute(['owner' => $submitterId, 'period' => $period]);
            $batchId = (int) $pdo->lastInsertId();

            // Only the submitter's own lines — a forged template id from someone else's sheet is ignored.
            $templateStmt = $pdo->prepare('SELECT * FROM recurring_templates WHERE id = :id AND owner_id = :owner AND active = 1');
            $numbers = [];
            $submitter = null;

            foreach ($amountsCentsByTemplateId as $templateId => $amountCents) {
                $templateStmt->execute(['id' => $templateId, 'owner' => $submitterId]);
                $template = $templateStmt->fetch();
                if (!$template || $amountCents <= 0) {
                    continue;
                }
                // Lines are checked against the person's assignments when saved; check again now,
                // so a line an admin has since taken away can't keep producing requests.
                $submitter ??= UserRepository::find($submitterId);
                if ($template['budget_line_id'] && (!$submitter || !BudgetLineRepository::isVisibleTo((int) $template['budget_line_id'], $submitter))) {
                    throw new WorkflowException("The budget line for {$template['payable_to']} is no longer available to you. Remove it from your sheet, or ask Admin / Tech to assign it again.");
                }

                $number = RequestNumberGenerator::next($pdo);
                $requestStmt = $pdo->prepare(
                    "INSERT INTO requests
                        (number, kind, fiscal_year, status, requester_id, payable_to, remit_address, payment_method,
                         date_needed, justification, is_recurring, recurring_id, batch_id, total_cents)
                     VALUES
                        (:number, 'expenditure', :fiscal_year, 'pending', :requester, :payable_to, 'On file', :payment,
                         :date_needed, :justification, 1, :recurring_id, :batch_id, :total)"
                );
                $requestStmt->execute([
                    'number'        => $number,
                    'fiscal_year'   => \App\Support\FiscalYear::forDate($period . '-15'), // the billing month's FY
                    'requester'     => $submitterId,
                    'payable_to'    => $template['payable_to'],
                    'payment'       => $template['payment_method'],
                    'date_needed'   => date('Y-m-d', strtotime($period . '-15')),
                    'justification' => "Monthly recurring — {$period} batch.",
                    'recurring_id'  => $templateId,
                    'batch_id'      => $batchId,
                    'total'         => $amountCents,
                ]);
                $requestId = (int) $pdo->lastInsertId();

                $lineStmt = $pdo->prepare(
                    'INSERT INTO request_lines (request_id, budget_line_id, class_id, amount_cents, sort)
                     VALUES (:rid, :bid, :cid, :amt, 0)'
                );
                $lineStmt->execute([
                    'rid' => $requestId,
                    'bid' => $template['budget_line_id'],
                    'cid' => $template['class_id'],
                    'amt' => $amountCents,
                ]);

                $pdo->prepare('UPDATE recurring_templates SET last_cents = :amt WHERE id = :id')
                    ->execute(['amt' => $amountCents, 'id' => $templateId]);

                $numbers[] = $number;
            }

            if (!$numbers) {
                throw new WorkflowException('Nothing to submit — tick at least one line with an amount above $0.00.');
            }
            return $numbers;
        });
    }
}

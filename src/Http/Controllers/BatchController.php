<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\BudgetLineRepository;
use App\Domain\RecurringBatchService;
use App\Domain\WorkflowException;
use App\Http\Controller;
use App\Support\Db;
use App\Support\Money;

/**
 * Monthly batch: each person's own sheet of recurring lines. They confirm /
 * adjust / skip and submit it once a month, and maintain the lines on it
 * (add, edit, remove) on the same screen.
 */
final class BatchController extends Controller
{
    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'batch';
    }

    /** The person's monthly sheet, optionally with a line open for editing (?edit=id). */
    public function show(): void
    {
        $user = $this->requireLogin();
        $draft = null;
        if (isset($_GET['edit'])) {
            $t = RecurringBatchService::findOwned((int) $_GET['edit'], (int) $user['id']);
            if ($t) {
                $draft = [
                    'id' => (int) $t['id'], 'payable_to' => $t['payable_to'], 'budget_line_id' => (int) $t['budget_line_id'],
                    'amount' => number_format($t['default_cents'] / 100, 2, '.', ''), 'payment_method' => $t['payment_method'],
                ];
            }
        }
        $this->renderSheet($user, $draft, []);
    }

    /** Create one pending request per included line for the current month's batch (RecurringBatchService may refuse, e.g. a repeat submission). */
    public function submit(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $period = date('Y-m');

        $included = array_map('intval', $_POST['include'] ?? []);
        $amounts = $_POST['amount'] ?? [];
        $amountsCents = [];
        foreach ($included as $templateId) {
            $amountsCents[$templateId] = Money::toCents((string) ($amounts[$templateId] ?? '0'));
        }

        try {
            $numbers = RecurringBatchService::submit((int) $user['id'], $period, $amountsCents);
            AuditLogger::log('recurring_batch', null, 'submit', count($numbers) . " recurring requests submitted as the " . date('F Y', strtotime($period . '-01')) . ' batch (' . implode(', ', $numbers) . ')');
            $this->flash(count($numbers) . ' recurring requests submitted as the ' . date('F Y', strtotime($period . '-01')) . ' batch.');
        } catch (WorkflowException $e) {
            $this->flash($e->getMessage());
            $this->redirect('/batch');
            return;
        }

        $this->redirect('/requests');
    }

    /** POST /batch/lines (new) or /batch/lines/{id} (edit). */
    public function saveLine(array $params): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $id = isset($params['id']) ? (int) $params['id'] : null;

        $draft = [
            'id' => $id,
            'payable_to' => (string) ($_POST['payable_to'] ?? ''),
            'budget_line_id' => (int) ($_POST['budget_line_id'] ?? 0),
            'amount' => (string) ($_POST['amount'] ?? ''),
            'payment_method' => (string) ($_POST['payment_method'] ?? 'card'),
        ];
        $errors = RecurringBatchService::save(
            (int) $user['id'], $id, $draft['payable_to'], $draft['budget_line_id'], Money::toCents($draft['amount']), $draft['payment_method']
        );
        if ($errors) {
            http_response_code(422);
            $this->renderSheet($user, $draft, $errors);
            return;
        }

        $payee = trim($draft['payable_to']);
        AuditLogger::log('recurring_template', $id, $id ? 'edit' : 'create', ($id ? 'Edited' : 'Added') . " recurring line: {$payee}, " . Money::format(Money::toCents($draft['amount'])) . ' a month');
        $this->flash($id ? "Recurring line for {$payee} updated." : "{$payee} added to your monthly sheet.");
        $this->redirect('/batch#manage');
    }

    /** Take a recurring line off the sheet (past requests are unchanged). */
    public function removeLine(array $params): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $t = RecurringBatchService::findOwned((int) $params['id'], (int) $user['id']);
        if ($t && RecurringBatchService::deactivate((int) $t['id'], (int) $user['id'])) {
            AuditLogger::log('recurring_template', (int) $t['id'], 'remove', "Removed recurring line: {$t['payable_to']}");
            $this->flash("{$t['payable_to']} removed from your monthly sheet. Past requests from it are unchanged.");
        } else {
            $this->flash('That recurring line no longer exists.');
        }
        $this->redirect('/batch#manage');
    }

    private function renderSheet(array $user, ?array $draft, array $errors): void
    {
        $period = date('Y-m');
        $templates = RecurringBatchService::templatesFor((int) $user['id']);

        $viewTemplates = array_map(fn ($t) => $t + [
            'last_formatted' => $t['last_cents'] > 0 ? Money::format((int) $t['last_cents']) : '—',
            'default_formatted' => Money::format((int) $t['default_cents']),
            'default_display' => number_format($t['default_cents'] / 100, 2, '.', ''),
            'default_checked' => true,
            'payment_label' => RecurringBatchService::PAYMENT_METHODS[$t['payment_method']] ?? $t['payment_method'],
        ], $templates);

        // Recurring lines are set up against the current fiscal year's budget
        // lines. Each option is one class's row, so the class is in its label;
        // the dropdown is grouped by account series (83, 84, 85 …).
        $all = [];
        foreach (BudgetLineRepository::classes() as $c) {
            foreach (BudgetLineRepository::forClassVisibleTo((int) $c['id'], $user, \App\Support\FiscalYear::current()) as $l) {
                $all[] = $l + ['class_name' => $c['name']];
            }
        }
        usort($all, fn ($a, $b) => [$a['code'], $a['class_name']] <=> [$b['code'], $b['class_name']]);
        $lineGroups = array_map(
            fn ($g) => ['name' => $g['series'], 'lines' => $g['items']],
            BudgetLineRepository::groupBySeries($all, fn ($l) => $l['code'])
        );

        $this->render('batch.twig', [
            'period_label' => date('F Y', strtotime($period . '-01')),
            'templates' => $viewTemplates,
            'already_submitted' => $this->alreadySubmitted((int) $user['id'], $period),
            'default_included_count' => count($templates),
            'default_total_formatted' => Money::format((int) array_sum(array_column($templates, 'default_cents'))),
            'line_groups' => $lineGroups,
            'payment_methods' => RecurringBatchService::PAYMENT_METHODS,
            'draft' => $draft ?? ['id' => null, 'payable_to' => '', 'budget_line_id' => 0, 'amount' => '', 'payment_method' => 'card'],
            'editing' => $draft !== null && $draft['id'] !== null,
            'errors' => $errors,
        ]);
    }

    private function alreadySubmitted(int $ownerId, string $period): bool
    {
        $stmt = Db::conn()->prepare('SELECT id FROM recurring_batches WHERE owner_id = :o AND period = :p');
        $stmt->execute(['o' => $ownerId, 'p' => $period]);
        return (bool) $stmt->fetch();
    }
}

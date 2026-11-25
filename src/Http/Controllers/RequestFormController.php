<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AuditLogger;
use App\Domain\BudgetLineRepository;
use App\Domain\RequestRepository;
use App\Domain\UploadService;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\FiscalYear;
use App\Support\Money;
use App\Validation\RequestValidator;

/** Create, edit and delete requests (the paper form). Edit and delete only while pending, enforced in SQL. */
final class RequestFormController extends Controller
{
    private const PAYMENT_METHODS = [
        'check' => 'Check', 'card' => 'Credit card', 'ach' => 'ACH / EFT', 'reimbursement' => 'Staff reimbursement',
    ];

    /**
     * Whose budget lines apply: the requester's. Normally that's the signed-in
     * person; when Admin / Tech edits someone else's request it's the original
     * requester, so the dropdown and the server-side check match what that
     * person may charge to.
     */
    private ?array $lineOwner = null;

    private function lineOwner(): array
    {
        return $this->lineOwner ?? (Auth::user() ?? []);
    }

    private function useRequesterLines(array $request): void
    {
        $this->lineOwner = \App\Domain\UserRepository::find((int) $request['requester_id']) ?? Auth::user();
        $this->existingFiscalYear = $request['fiscal_year'] ?? null;
        $this->editingRequestId = (int) $request['id'];
    }

    /** Request being edited — its own amounts aren't counted in "requested so far". */
    private ?int $editingRequestId = null;

    /** FY of the request being edited — still allowed even once it's outside the usual choices. */
    private ?string $existingFiscalYear = null;

    /** Fiscal years this form may charge to: last, this and next FY (+ an edited request's own FY). */
    private function fiscalYearChoices(): array
    {
        $choices = FiscalYear::choices();
        if ($this->existingFiscalYear && !isset($choices[$this->existingFiscalYear]) && FiscalYear::isValid($this->existingFiscalYear)) {
            $choices = [$this->existingFiscalYear => 'FY ' . $this->existingFiscalYear . ' (' . FiscalYear::span($this->existingFiscalYear) . ')'] + $choices;
        }
        return $choices;
    }

    /** The FY posted with the form, if it's one of the allowed choices; otherwise the current FY. */
    private function postedFiscalYear(): string
    {
        $fy = (string) ($_POST['fiscal_year'] ?? '');
        return isset($this->fiscalYearChoices()[$fy]) ? $fy : FiscalYear::current();
    }

    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'new';
    }

    /** Blank request form. */
    public function showNew(): void
    {
        $this->requireLogin();
        $form = [
            'kind' => 'expenditure',
            'fiscal_year' => FiscalYear::current(),
            'lines' => [['class_id' => 1, 'code' => '84.32', 'is_custom' => false, 'amount_display' => '']],
        ];
        $this->render('new_request.twig', $this->formContext($form, [], '/requests', 'Submit for approval', 'New request', '/requests'));
    }

    /** Validate and save a new request with its lines and attachment, then notify. */
    public function create(): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();

        [$errors, $lines, $payableTo] = $this->validateAndResolveLines();

        if ($errors) {
            $this->rerenderWithErrors($errors, '/requests', 'Submit for approval', 'New request', '/requests');
            return;
        }

        $attachment = $this->handleAttachmentUpload();

        $id = RequestRepository::create([
            'kind'           => $this->resolveKind($_POST['kind'] ?? null),
            'fiscal_year'    => $this->postedFiscalYear(),
            'requester_id'   => $user['id'],
            'payable_to'     => $payableTo,
            'remit_address'  => trim((string) ($_POST['remit_address'] ?? '')),
            'payment_method' => $this->resolvePaymentMethod($_POST['payment_method'] ?? null),
            'date_needed'    => $this->resolveDate($_POST['date_needed'] ?? null),
            'justification'  => trim((string) ($_POST['justification'] ?? '')),
            'is_recurring'   => !empty($_POST['is_recurring']),
        ], $lines);

        if ($attachment) {
            RequestRepository::addAttachment($id, $attachment['orig_name'], $attachment['stored_path'], $attachment['mime'], $attachment['bytes'], (int) $user['id']);
        }

        $request = RequestRepository::find($id);
        AuditLogger::log('request', $id, 'create', "Created {$request['number']} (" . Money::format((int) $request['total_cents']) . " · {$request['payable_to']})");

        $this->flash("Request {$request['number']} submitted. It appears in the Executive Director's queue and in the next weekly digest.");
        $this->redirect("/requests/{$id}");
    }

    /** Form pre-filled from a pending request. */
    public function showEdit(array $params): void
    {
        $user = $this->requireLogin();
        $request = RequestRepository::find((int) $params['id']);
        if (!$request || !$this->canEdit($request, $user)) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }
        $this->useRequesterLines($request);

        $form = [
            'kind' => $request['kind'],
            'fiscal_year' => $request['fiscal_year'] ?: FiscalYear::forDate($request['created_at']),
            'is_recurring' => (bool) $request['is_recurring'],
            'date_needed' => $request['date_needed'],
            'payment_method' => $request['payment_method'],
            'payable_to' => $request['payable_to'],
            'remit_address' => $request['remit_address'],
            'justification' => $request['justification'],
            'existing_attachment_name' => $request['attachments'][0]['orig_name'] ?? null,
            // An unverified (staff free-texted) line re-opens as free text rather than a
            // dropdown pick, since it was never promoted into the curated list.
            'lines' => array_map(fn ($l) => [
                'class_id' => (int) $l['class_id'],
                'code' => (int) ($l['line_verified'] ?? 0) === 1 ? $l['line_code'] : '__custom__',
                'is_custom' => (int) ($l['line_verified'] ?? 0) !== 1,
                'free_text' => (int) ($l['line_verified'] ?? 0) !== 1 ? trim($l['line_code'] . ' · ' . $l['line_label']) : '',
                'amount_display' => number_format($l['amount_cents'] / 100, 2, '.', ''),
            ], $request['lines']),
        ];

        $this->render('new_request.twig', $this->formContext(
            $form, [], "/requests/{$request['id']}", 'Save & re-notify approvers', 'Edit request', "/requests/{$request['id']}"
        ));
    }

    /** Save edits to a pending request. */
    public function update(array $params): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $request = RequestRepository::find($id);
        if (!$request || !$this->canEdit($request, $user)) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }
        $this->useRequesterLines($request);

        [$errors, $lines, $payableTo] = $this->validateAndResolveLines();
        if ($errors) {
            $this->rerenderWithErrors($errors, "/requests/{$id}", 'Save & re-notify approvers', 'Edit request', "/requests/{$id}");
            return;
        }

        $attachment = $this->handleAttachmentUpload();

        $ok = RequestRepository::updateIfEditable($id, (int) $user['id'], Auth::isAdmin(), [
            'kind'           => $this->resolveKind($_POST['kind'] ?? null),
            'fiscal_year'    => $this->postedFiscalYear(),
            'payable_to'     => $payableTo,
            'remit_address'  => trim((string) ($_POST['remit_address'] ?? '')),
            'payment_method' => $this->resolvePaymentMethod($_POST['payment_method'] ?? null),
            'date_needed'    => $this->resolveDate($_POST['date_needed'] ?? null),
            'justification'  => trim((string) ($_POST['justification'] ?? '')),
            'is_recurring'   => !empty($_POST['is_recurring']),
        ], $lines);

        if (!$ok) {
            http_response_code(409);
            echo "This request can no longer be edited — it may have already been approved.";
            return;
        }

        if ($attachment) {
            RequestRepository::addAttachment($id, $attachment['orig_name'], $attachment['stored_path'], $attachment['mime'], $attachment['bytes'], (int) $user['id']);
        }

        AuditLogger::log('request', $id, 'edit', "Edited {$request['number']} · approvers re-notified");
        $this->flash("Request {$request['number']} updated. Approvers have been re-notified.");
        $this->redirect("/requests/{$id}");
    }

    /** Delete a pending request. */
    public function delete(array $params): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf();
        $id = (int) $params['id'];
        $request = RequestRepository::find($id);

        if ($request && RequestRepository::deleteIfDeletable($id, (int) $user['id'], Auth::isAdmin())) {
            AuditLogger::log('request', $id, 'delete', "Deleted unapproved {$request['number']}");
            $this->flash("{$request['number']} deleted. Only unapproved requests can be removed.");
        } else {
            $this->flash('That request can no longer be deleted.');
        }
        $this->redirect('/requests');
    }

    /**
     * Both are ENUM columns in MySQL, so an out-of-list value would otherwise
     * surface as an uncaught PDOException (a 500 with a stack trace, on an
     * unauthenticated-adjacent path if it ever ends up there) rather than
     * quietly falling back to a sane default the way a tampered <select>
     * value should.
     */
    private function resolveKind(?string $value): string
    {
        return $value === 'encumbrance' ? 'encumbrance' : 'expenditure';
    }

    private function resolvePaymentMethod(?string $value): string
    {
        return array_key_exists((string) $value, self::PAYMENT_METHODS) ? $value : 'check';
    }

    /** A tampered or malformed date never reaches the DB — it's simply treated as "not set". */
    private function resolveDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        return ($date && $date->format('Y-m-d') === $value) ? $value : null;
    }

    private function canEdit(array $request, array $user): bool
    {
        return $request['status'] === 'pending' && ((int) $request['requester_id'] === (int) $user['id'] || Auth::isAdmin());
    }

    /** @return array{0:array,1:array,2:string} [errors, resolved lines, payable_to] */
    private function validateAndResolveLines(): array
    {
        $result = RequestValidator::validate($_POST);
        if ($result['errors']) {
            return [$result['errors'], [], $result['payable_to']];
        }

        $resolved = [];
        foreach ($result['lines'] as $i => $line) {
            if ($line['amount_cents'] <= 0) {
                continue; // blank/zero rows are simply dropped, matching the prototype
            }
            if ($line['is_custom']) {
                [$code, $label] = $this->splitFreeText($line['free_text']);
                $budgetLineId = BudgetLineRepository::createUnverified($line['class_id'], $code, $label, $this->postedFiscalYear());
                // Their own new line stays theirs once admin promotes it.
                BudgetLineRepository::assignIfRestricted((int) ($this->lineOwner()['id'] ?? 0), $budgetLineId);
                $resolved[] = ['budget_line_id' => $budgetLineId, 'free_text' => null, 'class_id' => $line['class_id'], 'amount_cents' => $line['amount_cents']];
                continue;
            }
            $match = $this->findBudgetLine($line['class_id'], $line['code'], $this->postedFiscalYear());
            if (!$match) {
                return [["line_{$i}" => 'That budget line isn’t available for FY ' . $this->postedFiscalYear() . ' — pick another.'], [], $result['payable_to']];
            }
            $resolved[] = ['budget_line_id' => $match['id'], 'free_text' => null, 'class_id' => $line['class_id'], 'amount_cents' => $line['amount_cents']];
        }

        return [[], $resolved, $result['payable_to']];
    }

    /** Server-side too: a request can't use a line that isn't available to its requester. */
    private function findBudgetLine(int $classId, string $code, string $fiscalYear): ?array
    {
        foreach (BudgetLineRepository::forClassVisibleTo($classId, $this->lineOwner(), $fiscalYear) as $line) {
            if ($line['code'] === $code) {
                return $line;
            }
        }
        return null;
    }

    /** @return array{0:string,1:string} [code, label] */
    private function splitFreeText(string $freeText): array
    {
        $code = '';
        $label = $freeText;
        if (str_contains($freeText, '·')) {
            [$code, $label] = array_map('trim', explode('·', $freeText, 2));
            $label = $label !== '' ? $label : $freeText;
        }
        // Fit the budget_lines columns (code 20, label 160).
        $label = mb_substr($label, 0, 160);
        if ($code === '') {
            // No account number typed: give each distinct description its own
            // placeholder code, so two different "Other" lines never share a
            // row (class/code/FY is unique, and same code means same line).
            $code = 'NEW-' . substr(hash('sha256', mb_strtolower($label)), 0, 8);
        }
        return [mb_substr($code, 0, 20), $label];
    }

    private function handleAttachmentUpload(): ?array
    {
        if (empty($_FILES['attachment']['name'])) {
            return null;
        }
        try {
            return UploadService::storeAttachment($_FILES['attachment']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->flash($e->getMessage());
            return null;
        }
    }

    private function rerenderWithErrors(array $errors, string $action, string $submitLabel, string $heading, string $cancel): void
    {
        $form = [
            'kind' => $_POST['kind'] ?? 'expenditure',
            'fiscal_year' => $this->postedFiscalYear(),
            'is_recurring' => !empty($_POST['is_recurring']),
            'date_needed' => $_POST['date_needed'] ?? '',
            'payment_method' => $_POST['payment_method'] ?? 'check',
            'payable_to' => $_POST['payable_to'] ?? '',
            'remit_address' => $_POST['remit_address'] ?? '',
            'justification' => $_POST['justification'] ?? '',
            'lines' => $this->reconstructLinesFromPost(),
        ];
        http_response_code(422);
        $this->render('new_request.twig', $this->formContext($form, $errors, $action, $submitLabel, $heading, $cancel));
    }

    private function reconstructLinesFromPost(): array
    {
        $classes = $_POST['line_class'] ?? [1];
        $codes = $_POST['line_code'] ?? [];
        $customs = $_POST['line_custom'] ?? [];
        $amounts = $_POST['line_amount'] ?? [];
        $lines = [];
        foreach (array_keys($classes) as $i) {
            $lines[] = [
                'class_id' => (int) $classes[$i],
                'code' => $codes[$i] ?? '',
                'is_custom' => ($codes[$i] ?? '') === '__custom__',
                'free_text' => $customs[$i] ?? '',
                'amount_display' => $amounts[$i] ?? '',
            ];
        }
        return $lines ?: [['class_id' => 1, 'code' => '84.32', 'is_custom' => false, 'amount_display' => '']];
    }

    private function formContext(array $form, array $errors, string $action, string $submitLabel, string $heading, string $cancel): array
    {
        // Restricted people see only their assigned + shared lines; ED and bookkeeper see all.
        // Lines belong to a fiscal year: the dropdowns show the form's FY, and the
        // JSON carries every selectable FY so switching FY re-fills them in place.
        $viewer = $this->lineOwner();
        $formFy = $form['fiscal_year'] ?? FiscalYear::current();
        $choices = $this->fiscalYearChoices();
        $usage = BudgetLineRepository::usageByLine($this->editingRequestId);
        $byFy = [];
        $summary = []; // per FY: the requester's lines with budget figures, grouped 83/84/85…
        foreach (array_keys($choices) as $fy) {
            $fyRows = [];
            foreach (BudgetLineRepository::classes() as $c) {
                $visible = BudgetLineRepository::forClassVisibleTo((int) $c['id'], $viewer, $fy);
                $byFy[$fy][(string) $c['id']] = array_map(function ($l) use ($usage) {
                    $f = BudgetLineRepository::budgetFigures($l, $usage);
                    return [
                        'value' => $l['code'], 'label' => $l['code'] . ' · ' . $l['label'],
                        'group' => BudgetLineRepository::series($l['code']),
                        'budget' => $f['budget'], 'approved' => $f['approved'], 'pending' => $f['pending'], 'remaining' => $f['remaining'],
                    ];
                }, $visible);
                foreach ($visible as $l) {
                    $f = BudgetLineRepository::budgetFigures($l, $usage);
                    $fyRows[] = ['code' => $l['code'], 'label' => $l['label'], 'class' => $c['name'],
                        'budget' => $f['budget'] !== null ? Money::format($f['budget']) : null,
                        'approved' => Money::format($f['approved']), 'pending' => Money::format($f['pending']),
                        'remaining' => $f['remaining'] === null ? null
                            : ($f['remaining'] < 0 ? 'over by ' . Money::format(-$f['remaining']) : Money::format($f['remaining'])),
                        'over' => $f['remaining'] !== null && $f['remaining'] < 0];
                }
            }
            usort($fyRows, fn ($a, $b) => [$a['code'], $a['class']] <=> [$b['code'], $b['class']]);
            $summary[$fy] = BudgetLineRepository::groupBySeries($fyRows, fn ($r) => $r['code']);
        }
        $mapping = $byFy[$formFy] ?? [];
        $classes = array_map(function ($c) use ($mapping) {
            $c['lines'] = $mapping[(string) $c['id']] ?? [];
            // Same options split into 83/84/85… groups for <optgroup>s.
            $c['line_groups'] = BudgetLineRepository::groupBySeries($c['lines'], fn ($o) => $o['value']);
            return $c;
        }, BudgetLineRepository::classes());

        $firstVisible = null;
        foreach ($classes as $c) {
            if ($firstVisible === null && $c['lines']) {
                $firstVisible = ['class_id' => (int) $c['id'], 'code' => $c['lines'][0]['value']];
            }
        }
        // A blank starting row whose default line this person can't use opens on
        // their first available line instead.
        foreach ($form['lines'] as &$l) {
            $codes = array_column($mapping[(string) ($l['class_id'] ?? '')] ?? [], 'value');
            if ($firstVisible && empty($l['is_custom']) && ($l['amount_display'] ?? '') === '' && !in_array($l['code'] ?? '', $codes, true)) {
                $l = array_merge($l, $firstVisible);
            }
        }
        unset($l);

        $total = 0;
        foreach ($form['lines'] as $l) {
            $total += (int) round(((float) preg_replace('/[^0-9.\-]/', '', (string) ($l['amount_display'] ?? '0'))) * 100);
        }

        return [
            'form' => $form,
            'errors' => $errors,
            'classes' => $classes,
            'budget_lines_json' => json_encode($byFy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'fiscal_year_choices' => $choices,
            'budget_summary' => $summary,
            'lines_by_fy_count' => array_map(fn ($byClass) => array_sum(array_map('count', $byClass)), $byFy),
            'payment_methods' => self::PAYMENT_METHODS,
            'form_heading' => $heading,
            'submit_label' => $submitLabel,
            'form_action' => $action,
            'cancel_url' => $cancel,
            'form_total' => Money::format($total),
        ];
    }
}

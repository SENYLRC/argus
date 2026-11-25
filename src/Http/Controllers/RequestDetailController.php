<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\RequestRepository;
use App\Domain\SignatureRepository;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Money;

/** One request: lines, history/sign-offs and the actions the viewer's role allows. */
final class RequestDetailController extends Controller
{
    private const STATUS_LABELS = ['pending' => 'Awaiting ED', 'approved' => 'Approved', 'complete' => 'Complete', 'rejected' => 'Rejected'];
    private const PAYMENT_LABELS = ['check' => 'Check', 'card' => 'Credit card', 'ach' => 'ACH / EFT', 'reimbursement' => 'Staff reimbursement'];

    /** GET /requests/{id}; staff get a 404 for anyone else's request. */
    public function show(array $params): void
    {
        $user = $this->requireLogin();
        $request = RequestRepository::find((int) $params['id']);

        if (!$request || (Auth::isStaff() && (int) $request['requester_id'] !== (int) $user['id'])) {
            http_response_code(404);
            $this->render('errors/404.twig');
            return;
        }

        $canApprove = $request['status'] === 'pending' && Auth::isApprover();
        $canEdit = $request['status'] === 'pending' && ((int) $request['requester_id'] === (int) $user['id'] || Auth::isAdmin());

        $lockedNote = null;
        if ($request['status'] === 'complete') {
            $lockedNote = 'Complete and posted ' . ($request['posted_date'] ? date('M j, Y', strtotime($request['posted_date'])) : '') . '. The record is locked; only Admin can override.';
        } elseif ($request['status'] === 'rejected') {
            $lockedNote = 'Rejected. The requester can duplicate it into a new request.';
        } elseif ($request['status'] === 'approved') {
            // Approval is final here; payment is handled in QuickBooks.
            $lockedNote = 'Approved and signed — the record is locked. The bookkeeper processes it in QuickBooks; print it from “View / print PDF record”.';
        } elseif ($request['status'] === 'pending' && !$canApprove && !$canEdit) {
            $lockedNote = 'Awaiting Executive Director approval.';
        }

        $request['status_label'] = self::STATUS_LABELS[$request['status']];
        $request['total_formatted'] = Money::format((int) $request['total_cents']);
        $request['submitted'] = date('F j, Y', strtotime($request['created_at']));
        $request['date_needed_formatted'] = $request['date_needed'] ? date('F j, Y', strtotime($request['date_needed'])) : null;
        $request['payment_label'] = self::PAYMENT_LABELS[$request['payment_method']] ?? $request['payment_method'];
        foreach ($request['lines'] as &$line) {
            $line['amount_formatted'] = Money::format((int) $line['amount_cents']);
        }
        unset($line);

        $sigOptions = [];
        if ($canApprove) {
            $signatures = SignatureRepository::forUser((int) $user['id']);
            foreach ($signatures as $sg) {
                $sigOptions[] = [
                    'id' => $sg['id'],
                    'preview' => $user['name'],
                    'label_with_default' => basename($sg['stored_path']) . ($sg['is_default'] ? ' · default' : ''),
                    'selected' => (bool) $sg['is_default'] || count($sigOptions) === 0,
                ];
            }
            // Only one tile should ever read "selected" — the loop above defaults the
            // first signature to selected only when nothing is explicitly the default.
            if (count(array_filter($sigOptions, fn ($s) => $s['selected'])) > 1) {
                $seenDefault = false;
                foreach ($sigOptions as &$opt) {
                    if ($opt['selected']) {
                        if ($seenDefault) {
                            $opt['selected'] = false;
                        }
                        $seenDefault = true;
                    }
                }
                unset($opt);
            }
        }

        $this->render('detail.twig', [
            'request' => $request,
            'timeline' => RequestRepository::timeline($request),
            'can_approve' => $canApprove,
            'can_complete' => false, // bookkeeper completion step removed 2026-10-04
            'can_edit' => $canEdit,
            'locked_note' => $lockedNote,
            'sig_options' => $sigOptions,
            'check_field_label' => $request['kind'] === 'encumbrance' ? 'Enc. #' : 'Check #',
            'today' => date('Y-m-d'),
        ]);
    }
}

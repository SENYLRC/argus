<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\RequestRepository;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Money;

/** Landing screen: staff see their own requests, other roles the queue for their job. */
final class RequestListController extends Controller
{
    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return Auth::isStaff() ? 'mine' : 'list';
    }

    /** List with summary figures and the print-selection controls. */
    public function index(): void
    {
        $user = $this->requireLogin();
        $requesterId = Auth::isStaff() ? (int) $user['id'] : null;
        $rows = RequestRepository::listForRole($requesterId);
        $stats = RequestRepository::stats($rows);

        $kindLabels = ['expenditure' => 'Expenditure', 'encumbrance' => 'Encumbrance'];
        $statusLabels = ['pending' => 'Awaiting ED', 'approved' => 'Approved', 'complete' => 'Complete', 'rejected' => 'Rejected'];

        $viewRows = array_map(function ($r) use ($kindLabels, $statusLabels) {
            $r['kind_label'] = $kindLabels[$r['kind']] . ($r['is_recurring'] ? ' · monthly' : '');
            $r['total_formatted'] = Money::format((int) $r['total_cents']);
            $r['status_label'] = $statusLabels[$r['status']];
            $r['submitted'] = date('M j, Y', strtotime($r['created_at']));
            return $r;
        }, $rows);

        $titles = ['staff' => 'My requests', 'ed' => 'Approval queue', 'bookkeeper' => 'Approved requests', 'admin' => 'All requests'];
        $blurbs = [
            'staff' => 'Everything you have submitted. Anything still awaiting approval can be edited or deleted.',
            'ed' => 'Requests awaiting your signature, then everything already processed.',
            'bookkeeper' => 'Every request: approved ones are ready to enter in QuickBooks and print; pending ones are still with the Executive Director.',
            'admin' => 'Requests awaiting signature, then everything already processed, across every requester.',
        ];

        $fiscalYear = ((int) date('n')) >= 7 ? (int) date('Y') : (int) date('Y') - 1;

        $this->render('list.twig', [
            'list_title' => $titles[$user['role']],
            'list_blurb' => $blurbs[$user['role']],
            'stats' => $stats + ['value_formatted' => Money::format((int) $stats['value_cents'])],
            'fiscal_year_label' => $fiscalYear . '–' . substr((string) ($fiscalYear + 1), 2, 2),
            'rows' => $viewRows,
        ]);
    }
}

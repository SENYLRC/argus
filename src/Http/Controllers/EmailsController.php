<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\DigestBuilder;
use App\Http\Controller;
use App\Support\Auth;
use App\Support\Money;

/**
 * Read-only reference matching the design's Email notices tab: the three
 * notices the client chose. This screen only previews copy — it never sends
 * mail.
 *
 * Every role sees this tab, but the weekly digest and rejection notice are
 * org-wide, cross-requester data that only ED/bookkeeper/admin can otherwise
 * see anywhere else in the app (the queue itself is filtered to "my
 * requests" for staff). So a staff viewer gets the same illustrative example
 * as the original prototype instead of a live query — showing them a real
 * digest here would be an access-control bypass by the back door.
 */
final class EmailsController extends Controller
{
    /** Which navigation tab to mark current (aria-current="page"). */
    protected function activeTab(): string
    {
        return 'emails';
    }

    /** Preview of the three notices (illustrative data for staff, live for approvers/bookkeeper/admin). */
    public function index(): void
    {
        $this->requireLogin();
        $canSeeOrgWideData = Auth::isApprover() || Auth::isBookkeeperRole();

        $this->render('emails.twig', [
            'emails' => [
                $canSeeOrgWideData ? $this->rejectedExample() : $this->rejectedIllustration(),
                $canSeeOrgWideData ? $this->weeklyDigestExample() : $this->weeklyDigestIllustration(),
                $this->recurringReminderExample(), // the viewer's own lines only
            ],
        ]);
    }

    private function rejectedExample(): array
    {
        $stmt = \App\Support\Db::conn()->query("SELECT * FROM requests WHERE status = 'rejected' ORDER BY updated_at DESC LIMIT 1");
        $r = $stmt->fetch();
        if (!$r) {
            return $this->rejectedIllustration();
        }

        return $this->rejectedBody(
            $r['number'],
            $r['payable_to'],
            Money::format((int) $r['total_cents']),
            (string) $r['reject_reason']
        );
    }

    private function rejectedIllustration(): array
    {
        return $this->rejectedBody(
            'R-2026-041',
            'Springshare LLC',
            '$1,832.00',
            'Hold until the new fiscal year — resubmit after Sept 1 against 84.26.'
        );
    }

    private function rejectedBody(string $number, string $payableTo, string $amount, string $reason): array
    {
        return [
            'trigger' => 'On rejection', 'to' => 'requester', 'subject' => "Your expenditure request {$number} was not approved",
            'body' => "The Executive Director did not approve your request to {$payableTo} for {$amount}.\n\nReason given:\n\u{201C}{$reason}\u{201D}\n\nThe request stays in your history. You can duplicate it into a new request at any time.",
            'cta' => 'View the request',
        ];
    }

    private function weeklyDigestExample(): array
    {
        $d = DigestBuilder::weeklyDigest();
        if (!$d['pending_count'] && !$d['approved_count']) {
            return $this->weeklyDigestIllustration();
        }

        $lines = "Awaiting Executive Director signature\n";
        foreach ($d['awaiting_ed'] as $r) {
            $lines .= "  {$r['number']} · {$r['payable_to']} · {$r['amount']} · due {$r['date_needed']}\n";
        }
        $lines .= "\nApproved in the last 7 days — ready for QuickBooks\n";
        foreach ($d['approved_recent'] as $r) {
            $lines .= "  {$r['number']} · {$r['payable_to']} · {$r['amount']}\n";
        }
        if ($d['pending_count']) {
            $lines .= "\nLongest wait: {$d['oldest_days']} days.";
        }

        return [
            'trigger' => 'Mondays, 7:00 AM', 'to' => 'Executive Director, Bookkeeper',
            'subject' => "Requests this week — {$d['pending_count']} awaiting approval, {$d['approved_count']} approved",
            'body' => $lines, 'cta' => 'Open the requests',
        ];
    }

    private function weeklyDigestIllustration(): array
    {
        return [
            'trigger' => 'Mondays, 7:00 AM', 'to' => 'Executive Director, Bookkeeper',
            'subject' => 'Requests this week — 2 awaiting approval, 1 approved',
            'body' => "Awaiting Executive Director signature\n  R-2026-041 · Springshare LLC · \$1,832.00 · due Aug 28\n  R-2026-042 · Dell Technologies · \$220.00 · due Sept 20\n\nApproved in the last 7 days — ready for QuickBooks\n  R-2026-040 · Amazon Web Services · \$379.00\n\nLongest wait: 6 days.",
            'cta' => 'Open the requests',
        ];
    }

    private function recurringReminderExample(): array
    {
        $d = DigestBuilder::recurringReminder((int) Auth::id()); // the viewer's own sheet

        return [
            'trigger' => '1st of the month, 6:00 AM', 'to' => 'each person with recurring lines (their own sheet)',
            'subject' => 'Your monthly recurring expenses are ready to confirm',
            'body' => "Your recurring lines for {$d['period_label']} are waiting on the batch sheet. Confirm the amounts that are unchanged, adjust anything that moved, and skip what shouldn't go out.\n\n"
                . "{$d['line_count']} lines on your sheet · last month's total {$d['last_month_total']}\n\nPlease submit by the 10th so the bookkeeper can post on schedule.",
            'cta' => 'Open the batch sheet',
        ];
    }
}

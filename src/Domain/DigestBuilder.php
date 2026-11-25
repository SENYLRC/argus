<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Money;

/**
 * Builds the data behind the weekly pending digest and the monthly recurring
 * reminder (build-notes §4) — shared by the real cron sends (bin/console)
 * and the read-only Email notices preview screen, so the two never drift.
 */
final class DigestBuilder
{
    /**
     * Monday digest for the ED and bookkeeper: what's waiting for the ED's
     * signature, and what was approved in the last 7 days (the bookkeeper's
     * list to enter in QuickBooks — payment isn't tracked in this app).
     */
    public static function weeklyDigest(): array
    {
        $all = RequestRepository::listForRole(null);
        $awaitingEd = array_values(array_filter($all, fn ($r) => $r['status'] === 'pending'));
        $weekAgo = time() - 7 * 86400;
        $approvedRecent = array_values(array_filter(
            $all,
            fn ($r) => $r['status'] === 'approved' && !empty($r['approved_at']) && strtotime($r['approved_at']) >= $weekAgo
        ));

        $rowsFor = fn ($rows) => array_map(fn ($r) => [
            'number' => $r['number'], 'payable_to' => $r['payable_to'], 'amount' => Money::format((int) $r['total_cents']),
            'date_needed' => $r['date_needed'] ? date('M j', strtotime($r['date_needed'])) : 'unscheduled',
            'approved_on' => !empty($r['approved_at']) ? date('M j', strtotime($r['approved_at'])) : '',
        ], $rows);

        // Longest wait among requests still awaiting approval.
        $oldestDays = $awaitingEd
            ? max(array_map(fn ($r) => (int) floor((time() - strtotime($r['created_at'])) / 86400), $awaitingEd))
            : 0;

        return [
            'awaiting_ed'     => $rowsFor($awaitingEd),
            'approved_recent' => $rowsFor($approvedRecent),
            'oldest_days'     => $oldestDays,
            'pending_count'   => count($awaitingEd),
            'approved_count'  => count($approvedRecent),
            'pending_total'   => Money::format((int) array_sum(array_column($awaitingEd, 'total_cents'))),
        ];
    }

    /** One person's reminder (their own sheet), or the org-wide aggregate when $ownerId is null. */
    public static function recurringReminder(?int $ownerId = null): array
    {
        $templates = $ownerId === null ? RecurringBatchService::activeTemplates() : RecurringBatchService::templatesFor($ownerId);
        return [
            'period_label' => date('F Y'),
            'line_count' => count($templates),
            'last_month_total' => Money::format((int) array_sum(array_column($templates, 'last_cents'))),
        ];
    }
}

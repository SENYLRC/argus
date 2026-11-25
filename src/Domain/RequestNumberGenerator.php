<?php

declare(strict_types=1);

namespace App\Domain;

use PDO;

/**
 * "R-{fiscal_year_start}-{zero-padded sequence}", allocated in a transaction
 * (build-notes §2 "Notes"). The council's fiscal year starts July 1, so a
 * request made in July 2026 or later is FY2026 until July 2027.
 */
final class RequestNumberGenerator
{
    /**
     * Next number like R-2026-042 (fiscal year starts July 1). Must run inside the caller's transaction:
     * SELECT ... FOR UPDATE serialises concurrent submissions.
     */
    public static function next(PDO $pdo, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $fiscalYear = ((int) $now->format('n')) >= 7 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        $prefix = "R-{$fiscalYear}-";

        // SELECT ... FOR UPDATE inside the caller's transaction serializes concurrent allocations.
        $stmt = $pdo->prepare(
            "SELECT number FROM requests WHERE number LIKE :prefix ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['prefix' => $prefix . '%']);
        $last = $stmt->fetchColumn();

        $seq = 1;
        if ($last && preg_match('/-(\d+)$/', (string) $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}

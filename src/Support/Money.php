<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is stored in integer cents everywhere (build-notes §2, "never FLOAT")
 * and only formatted at the edges.
 */
final class Money
{
    /** Parse user-typed dollars ("$1,234.50") into integer cents; money is never stored as float. */
    public static function toCents(string $dollars): int
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $dollars) ?? '';
        return (int) round(((float) $clean) * 100);
    }

    /** Integer cents as "$1,234.50". */
    public static function format(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}

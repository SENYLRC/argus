<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The council's fiscal year runs July 1 – June 30. Labels look like
 * "2026-27" (the format budget_lines.fiscal_year and requests.fiscal_year use).
 */
final class FiscalYear
{
    /** FY containing a date (default: today). */
    public static function forDate(?string $date = null): string
    {
        $ts = $date !== null ? strtotime($date) : time();
        $start = ((int) date('n', $ts)) >= 7 ? (int) date('Y', $ts) : (int) date('Y', $ts) - 1;
        return self::label($start);
    }

    /** Label of the fiscal year containing today, e.g. "2026-27". */
    public static function current(): string
    {
        return self::forDate();
    }

    /** "2026-27" for a start year of 2026. */
    public static function label(int $startYear): string
    {
        return $startYear . '-' . substr((string) ($startYear + 1), 2, 2);
    }

    /** First calendar year of a label: "2026-27" gives 2026. */
    public static function startYear(string $label): int
    {
        return (int) substr($label, 0, 4);
    }

    /** True for a well-formed label whose second half is the next year ("2026-27", not "2026-28"). */
    public static function isValid(string $label): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})$/', $label, $m) && (int) $m[2] === ((int) $m[1] + 1) % 100;
    }

    /** "July 2026 – June 2027". */
    public static function span(string $label): string
    {
        $start = self::startYear($label);
        return "July {$start} – June " . ($start + 1);
    }

    /**
     * FYs a new request may be for: last year (late invoices in July/August),
     * this year, and next year (encumbering ahead). Keyed by label.
     * @return array<string,string> label => "FY 2026-27 (July 2026 – June 2027)"
     */
    public static function choices(): array
    {
        $current = self::startYear(self::current());
        $out = [];
        foreach ([$current - 1, $current, $current + 1] as $y) {
            $label = self::label($y);
            $out[$label] = "FY {$label} (" . self::span($label) . ')';
        }
        return $out;
    }
}

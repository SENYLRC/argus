<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\Money;

/**
 * Server-side validation for the New/Edit Request form (README §2:
 * "payee required, at least one non-zero amount"). Errors come back keyed by
 * field name so the template can wire aria-describedby + aria-invalid
 * (build-notes §4b 3.3.1/3.3.3), not just show a generic toast.
 */
final class RequestValidator
{
    /**
     * @return array{errors: array<string,string>, lines: array<int,array>, payable_to: string}
     */
    public static function validate(array $input): array
    {
        $errors = [];

        $payableTo = trim((string) ($input['payable_to'] ?? ''));
        if ($payableTo === '') {
            $errors['payable_to'] = 'Enter who this is payable to.';
        }

        $lines = [];
        $classes = $input['line_class'] ?? [];
        $codes = $input['line_code'] ?? [];
        $customs = $input['line_custom'] ?? [];
        $amounts = $input['line_amount'] ?? [];
        $anyPositive = false;

        foreach (array_keys($classes) as $i) {
            $amountCents = Money::toCents((string) ($amounts[$i] ?? '0'));
            $code = (string) ($codes[$i] ?? '');
            $isCustom = $code === '__custom__';
            $freeText = trim((string) ($customs[$i] ?? ''));

            if ($isCustom && $amountCents > 0 && $freeText === '') {
                $errors["line_{$i}"] = 'Enter a budget line, or choose one from the list.';
            }
            if ($amountCents > 0) {
                $anyPositive = true;
            }
            if ($amountCents < 0) {
                $errors["line_{$i}"] = 'Enter an amount greater than 0.';
            }

            $lines[] = [
                'class_id'   => (int) $classes[$i],
                'code'       => $code,
                'is_custom'  => $isCustom,
                'free_text'  => $freeText,
                'amount_cents' => $amountCents,
            ];
        }

        if (!$anyPositive) {
            $errors['amount'] = 'Enter an amount greater than 0 on at least one budget line.';
        }

        return ['errors' => $errors, 'lines' => $lines, 'payable_to' => $payableTo];
    }
}

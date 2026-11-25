<?php

declare(strict_types=1);

namespace App\Support;

use ZxcvbnPhp\Zxcvbn;

/**
 * One password policy for everywhere a password is set (set-password link,
 * My profile, bin/console user:create-admin). Admin-tunable under
 * Administration > Security:
 *
 *   - minimum length (default 12)
 *   - zxcvbn strength score (default ≥ 3 of 4): catches common passwords,
 *     keyboard patterns, dates, repeats, l33t substitutions
 *   - not built from the person's own name / email or the organisation's
 *     words (zxcvbn alone under-penalises these)
 *   - not in a known breach (Have I Been Pwned range API, k-anonymity: only
 *     the first 5 hex characters of the SHA-1 leave the server). If the
 *     service can't be reached the check is skipped, never blocking.
 */
final class PasswordPolicy
{
    private const MAX_LENGTH = 200;
    private const ORG_WORDS = ['senylrc', 'southeastern', 'argus', 'expenditure', 'encumbrance'];
    private const SCORE_WORDS = ['very weak', 'weak', 'fair', 'strong', 'very strong'];

    /** Minimum password length from settings (never below 8, default 12). */
    public static function minLength(): int
    {
        return max(8, (int) Settings::get('password_min_length', '12'));
    }

    /** Minimum zxcvbn strength score 0-4 from settings (default 3). */
    public static function minScore(): int
    {
        return min(4, max(0, (int) Settings::get('password_min_score', '3')));
    }

    /** Human description for form hints. */
    public static function hint(): string
    {
        return 'At least ' . self::minLength() . ' characters. A few unrelated words is strong and easy to remember; '
            . 'avoid your name, the organisation’s name, and common passwords.';
    }

    /**
     * Problems with a proposed password, as plain sentences ([] = acceptable).
     * $person: name and email of the account (used to reject passwords built from them).
     */
    public static function problems(string $password, array $person = []): array
    {
        $len = mb_strlen($password);
        if ($len < self::minLength()) {
            return ['Use at least ' . self::minLength() . ' characters (this one has ' . $len . ').'];
        }
        if ($len > self::MAX_LENGTH) {
            return ['Keep it under ' . self::MAX_LENGTH . ' characters.'];
        }

        $problems = [];
        $lower = mb_strtolower($password);
        foreach (self::personalWords($person) as $word) {
            if (str_contains($lower, $word)) {
                $problems[] = 'Don’t build it from your name, your email or the organisation’s name (“' . $word . '”).';
                break;
            }
        }

        $result = (new Zxcvbn())->passwordStrength($password, self::personalWords($person));
        if ((int) $result['score'] < self::minScore()) {
            $why = trim((string) ($result['feedback']['warning'] ?? ''));
            $tips = array_filter((array) ($result['feedback']['suggestions'] ?? []));
            $problems[] = 'That password is ' . self::SCORE_WORDS[(int) $result['score']] . '; it needs to be at least '
                . self::SCORE_WORDS[self::minScore()] . '.' . ($why !== '' ? ' ' . rtrim($why, '.') . '.' : '')
                . ($tips ? ' ' . implode(' ', $tips) : '');
        }

        if (!$problems && Settings::get('password_check_breached', '1') === '1') {
            $count = self::breachCount($password);
            if ($count > 0) {
                $problems[] = 'That password has appeared in ' . number_format($count) . ' known data breach' . ($count === 1 ? '' : 'es')
                    . ', so attackers try it first. Choose a different one.';
            }
        }
        return $problems;
    }

    /**
     * Times this password appears in Have I Been Pwned, or 0 (also 0 when the
     * service can't be reached). Only the first 5 hex characters of its SHA-1
     * are sent; the match happens here.
     */
    public static function breachCount(string $password): int
    {
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);
        $ch = curl_init('https://api.pwnedpasswords.com/range/' . $prefix);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Add-Padding: true', 'User-Agent: Argus-expense-requests'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) {
            return 0; // unreachable — don't block anyone on a third-party outage
        }
        foreach (preg_split('/\r?\n/', $body) as $line) {
            [$hash, $n] = array_pad(explode(':', trim($line), 2), 2, '0');
            if (hash_equals($suffix, $hash)) {
                return (int) $n;
            }
        }
        return 0;
    }

    /** Lower-case words a password mustn't contain: name parts, email name, org words (4+ letters). */
    private static function personalWords(array $person): array
    {
        $words = self::ORG_WORDS;
        $raw = (string) ($person['name'] ?? '') . ' ' . strstr((string) ($person['email'] ?? '') . '@', '@', true);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($raw)) ?: [] as $w) {
            if (mb_strlen($w) >= 4) {
                $words[] = $w;
            }
        }
        return array_values(array_unique($words));
    }
}

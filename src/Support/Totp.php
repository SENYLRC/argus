<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Time-based one-time codes (RFC 6238, the scheme every authenticator app
 * speaks): HMAC-SHA1, 6 digits, 30-second steps. Self-contained so there's
 * no extra dependency for something this small.
 */
final class Totp
{
    private const STEP = 30;
    private const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret, base32-encoded (160 bits, the RFC's recommended size). */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Check a code against the current step and one step either side (allows
     * ~30s of clock drift). Returns the matching step number, or null.
     * A step at or before $lastUsedStep is rejected, so an observed code
     * can't be replayed.
     */
    public static function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        $current = intdiv($now ?? time(), self::STEP);
        foreach ([$current - 1, $current, $current + 1] as $step) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::codeAt($key, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** otpauth:// URI for the QR code an authenticator app scans. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::STEP;
    }

    /** "ABCD EFGH …" — easier to type in by hand. */
    public static function grouped(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private static function codeAt(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
                | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=]+/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $i = strpos(self::BASE32, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}

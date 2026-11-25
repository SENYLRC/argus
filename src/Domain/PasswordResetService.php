<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * Single-use, 48-hour set-password / reset links (build-notes §2b). Only the
 * SHA-256 hash of the token is stored — the raw token exists only in the
 * emailed URL, same principle as a password.
 */
final class PasswordResetService
{
    private const TTL_HOURS = 48;

    /**
     * Create a single-use set-password token valid for TTL_HOURS. Only its SHA-256 hash is stored;
     * the plain token is returned once, for the emailed link.
     */
    public static function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = Db::conn()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:uid, :hash, :exp)'
        );
        $stmt->execute([
            'uid'  => $userId,
            'hash' => hash('sha256', $token),
            'exp'  => date('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600),
        ]);
        return $token;
    }

    /** True if a link was issued to this user in the last $minutes and is still unused (limits repeat emails). */
    public static function recentlyIssued(int $userId, int $minutes = 10): bool
    {
        $stmt = Db::conn()->prepare(
            'SELECT 1 FROM password_resets WHERE user_id = :u AND used_at IS NULL AND expires_at > NOW()
             AND created_at > NOW() - INTERVAL ' . max(1, $minutes) . ' MINUTE LIMIT 1'
        );
        $stmt->execute(['u' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Returns the user id for a valid, unused, unexpired token — or null. */
    public static function resolve(string $token): ?int
    {
        $stmt = Db::conn()->prepare(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row ? (int) $row['user_id'] : null;
    }

    /** Mark a token used so the link cannot be replayed. */
    public static function consume(string $token): void
    {
        $stmt = Db::conn()->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE token_hash = :hash'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * Self-service sign-in email change (My profile). A change only takes effect
 * once confirmed from a single-use link sent to the *new* address, valid for
 * 24 hours — so a typo can't lock someone out and nobody can move an account
 * to an address they don't control. Same token handling as
 * PasswordResetService: only the SHA-256 is stored.
 */
final class EmailChangeService
{
    private const TTL_HOURS = 24;

    /** Start a change; replaces any earlier pending one. Returns the raw token for the emailed link. */
    public static function request(int $userId, string $newEmail): string
    {
        self::cancel($userId);
        $token = bin2hex(random_bytes(32));
        Db::conn()->prepare(
            'INSERT INTO email_changes (user_id, new_email, token_hash, expires_at) VALUES (:u, :e, :h, :x)'
        )->execute([
            'u' => $userId, 'e' => $newEmail, 'h' => hash('sha256', $token),
            'x' => date('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600),
        ]);
        return $token;
    }

    /** The pending (unused, unexpired) change for a user, if any. */
    public static function pending(int $userId): ?array
    {
        try {
            $stmt = Db::conn()->prepare(
                'SELECT * FROM email_changes WHERE user_id = :u AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['u' => $userId]);
            return $stmt->fetch() ?: null;
        } catch (\PDOException) {
            return null; // migration not applied yet
        }
    }

    /** Look up a valid token's change row. */
    public static function resolve(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $stmt = Db::conn()->prepare(
            'SELECT * FROM email_changes WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['h' => hash('sha256', $token)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Apply a confirmed change. Returns the old email, or null if the token is
     * no longer valid or the address was taken by another account meanwhile.
     */
    public static function confirm(string $token): ?string
    {
        $change = self::resolve($token);
        if (!$change) {
            return null;
        }
        return Db::transaction(function (\PDO $pdo) use ($change) {
            $taken = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :u');
            $taken->execute(['e' => $change['new_email'], 'u' => $change['user_id']]);
            if ($taken->fetch()) {
                return null;
            }
            $old = $pdo->prepare('SELECT email FROM users WHERE id = :u');
            $old->execute(['u' => $change['user_id']]);
            $oldEmail = (string) $old->fetchColumn();
            // Signs out every session on the account (it's now a different login).
            $pdo->prepare('UPDATE users SET email = :e, session_version = session_version + 1 WHERE id = :u')
                ->execute(['e' => $change['new_email'], 'u' => $change['user_id']]);
            $pdo->prepare('UPDATE email_changes SET used_at = NOW() WHERE id = :id')->execute(['id' => $change['id']]);
            return $oldEmail;
        });
    }

    /** Void any pending email-change confirmation links for this user. */
    public static function cancel(int $userId): void
    {
        try {
            Db::conn()->prepare('UPDATE email_changes SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')->execute(['u' => $userId]);
        } catch (\PDOException) {
        }
    }
}

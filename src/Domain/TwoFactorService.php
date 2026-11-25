<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Crypto;
use App\Support\Db;
use App\Support\Totp;

/**
 * Optional two-step sign-in for any account (My profile > Two-step sign-in).
 * After the password, one of: a 6-digit code from an authenticator app, a
 * touch of a registered security key (YubiKey etc. — SecurityKeyService),
 * or one of ten one-time recovery codes. "On" means at least one app or key;
 * recovery codes are created with the first method and removed with the last.
 *
 * - The TOTP secret is stored encrypted (Crypto, APP_KEY in .env).
 * - Recovery codes are stored only as password_hash() values; the codes
 *   themselves are shown once.
 * - Each accepted app code advances totp_last_step, so a code can't be used
 *   twice.
 * - An admin "Reset" on Accounts clears two-step sign-in along with the
 *   password (UserRepository::clearCredentials), which is the lost-phone
 *   route when the recovery codes are gone too.
 */
final class TwoFactorService
{
    private const RECOVERY_COUNT = 10;
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I

    /** Two-step sign-in is on: an authenticator app and/or at least one security key. */
    public static function isEnabled(array $user): bool
    {
        return self::hasApp($user) || SecurityKeyService::count((int) $user['id']) > 0;
    }

    // ── Admin requirement (Administration > Security) ────────────────────────

    /** Setting: '' optional · 'privileged' ED, bookkeeper, Admin / Tech · 'all' everyone. */
    public static function requirement(): string
    {
        $r = \App\Support\Settings::get('require_2fa');
        return in_array($r, ['privileged', 'all'], true) ? $r : '';
    }

    /** Must this person have two-step sign-in? */
    public static function isRequiredFor(array $user): bool
    {
        return match (self::requirement()) {
            'all' => true,
            'privileged' => in_array($user['role'] ?? '', ['ed', 'bookkeeper', 'admin'], true),
            default => false,
        };
    }

    /** Required but not set up yet: they may only reach My profile until they add a method. */
    public static function setupRequired(array $user): bool
    {
        return self::isRequiredFor($user) && !self::isEnabled($user);
    }

    /** An authenticator app is set up. */
    public static function hasApp(array $user): bool
    {
        return !empty($user['totp_enabled_at']) && !empty($user['totp_secret']);
    }

    /**
     * Add the authenticator app once the person has proved it works (a valid
     * code for $secret). Returns new recovery codes to show once if this is
     * their first method, otherwise [] (existing codes stay valid).
     */
    public static function enable(int $userId, string $secret, int $acceptedStep): array
    {
        Db::conn()->prepare(
            'UPDATE users SET totp_secret = :s, totp_enabled_at = NOW(), totp_last_step = :step WHERE id = :id'
        )->execute(['s' => Crypto::encrypt($secret), 'step' => $acceptedStep, 'id' => $userId]);
        return self::ensureRecoveryCodes($userId);
    }

    /** First method added: create recovery codes (returned to show once). Otherwise []. */
    public static function ensureRecoveryCodes(int $userId): array
    {
        return self::remainingRecoveryCodes($userId) === 0 ? self::regenerateRecoveryCodes($userId) : [];
    }

    /** Turn two-step sign-in off completely: app, every security key, recovery codes. */
    public static function disable(int $userId): void
    {
        Db::conn()->prepare(
            'UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = :id'
        )->execute(['id' => $userId]);
        SecurityKeyService::removeAll($userId);
        Db::conn()->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
    }

    /** Remove just the authenticator app (security keys stay). */
    public static function disableApp(int $userId): void
    {
        Db::conn()->prepare(
            'UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = :id'
        )->execute(['id' => $userId]);
        self::dropRecoveryCodesIfOff($userId);
    }

    /** After removing a method: if none is left, the recovery codes go too. */
    public static function dropRecoveryCodesIfOff(int $userId): void
    {
        $user = UserRepository::find($userId);
        if ($user && !self::isEnabled($user)) {
            Db::conn()->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
        }
    }

    /**
     * Check a sign-in code: an app code first, then a recovery code.
     * @return 'app'|'recovery'|null
     */
    public static function verify(array $user, string $input): ?string
    {
        if (!self::isEnabled($user)) {
            return null;
        }
        $digits = preg_replace('/\s+/', '', $input) ?? '';
        if (preg_match('/^\d{6}$/', $digits)) {
            if (!self::hasApp($user)) {
                return null; // keys-only account: a 6-digit code can't be right
            }
            try {
                $secret = Crypto::decrypt((string) $user['totp_secret']);
            } catch (\RuntimeException) {
                return null; // APP_KEY changed — only a recovery code or an admin reset can get them in
            }
            $step = Totp::verify($secret, $digits, $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null);
            if ($step === null) {
                return null;
            }
            // Conditional update closes the race where the same code is sent twice at once.
            $stmt = Db::conn()->prepare(
                'UPDATE users SET totp_last_step = :step WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :step2)'
            );
            $stmt->execute(['step' => $step, 'step2' => $step, 'id' => $user['id']]);
            return $stmt->rowCount() === 1 ? 'app' : null;
        }
        return self::useRecoveryCode((int) $user['id'], $input) ? 'recovery' : null;
    }

    /** Check an app code against a not-yet-saved secret (setup confirmation). Returns the step, or null. */
    public static function verifySetupCode(string $secret, string $code): ?int
    {
        return Totp::verify($secret, $code);
    }

    /** Replace all recovery codes; returns the new plaintext codes (shown once). */
    public static function regenerateRecoveryCodes(int $userId): array
    {
        $codes = [];
        $max = strlen(self::RECOVERY_ALPHABET) - 1;
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 8; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, $max)];
            }
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4);
        }
        Db::transaction(function (\PDO $pdo) use ($userId, $codes) {
            $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
            $ins = $pdo->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (:id, :h)');
            foreach ($codes as $code) {
                $ins->execute(['id' => $userId, 'h' => password_hash(self::normalise($code), PASSWORD_DEFAULT)]);
            }
        });
        return $codes;
    }

    /** Unused recovery codes left for the user. */
    public static function remainingRecoveryCodes(int $userId): int
    {
        try {
            $stmt = Db::conn()->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :id AND used_at IS NULL');
            $stmt->execute(['id' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    private static function useRecoveryCode(int $userId, string $input): bool
    {
        $code = self::normalise($input);
        if (strlen($code) !== 8) {
            return false;
        }
        $stmt = Db::conn()->prepare('SELECT id, code_hash FROM user_recovery_codes WHERE user_id = :id AND used_at IS NULL');
        $stmt->execute(['id' => $userId]);
        foreach ($stmt->fetchAll() as $row) {
            if (password_verify($code, $row['code_hash'])) {
                $upd = Db::conn()->prepare('UPDATE user_recovery_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
                $upd->execute(['id' => $row['id']]);
                return $upd->rowCount() === 1;
            }
        }
        return false;
    }

    /** Upper-case, strip spaces and dashes: "abcd-efgh" and "ABCD EFGH" both work. */
    private static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');
    }
}

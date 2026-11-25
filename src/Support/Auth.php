<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Login/session identity + role helpers. Admin can always do what ED and
 * Bookkeeper can (build-notes §1's "Everything"), which is why isApprover()
 * and isBookkeeperRole() both include 'admin' — mirroring the prototype's
 * isApprover()/isBookkeeper() helpers.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    // A fixed, valid bcrypt hash of no real password — verified against on every
    // "user not found" login attempt so that path costs the same wall-clock time
    // as a real one. Without this, an unauthenticated caller could tell a known
    // email apart from an unknown one purely by how fast the response comes
    // back (password_verify() is deliberately slow; skipping it when the email
    // doesn't exist is a timing side-channel for account enumeration).
    private const DUMMY_HASH = '$2y$12$11eCa0JfDjfAxptwIFy84eh36feuq5t7o4j6.Q7tNouRnK5vYnhlu';

    /**
     * Password check only — honours the lockout, counts failures, and is
     * timing-safe for unknown emails. Returns the user row on success
     * without signing them in (two-step sign-in may still be needed).
     */
    public static function checkPassword(string $email, string $password): ?array
    {
        $stmt = Db::conn()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        if (!$row || !$row['active']) {
            password_verify($password, self::DUMMY_HASH);
            IpGuard::recordFailedLogin();
            return null;
        }
        if (self::isLocked($row)) {
            return null;
        }
        if ($row['password_hash'] === null || !password_verify($password, $row['password_hash'] ?? self::DUMMY_HASH)) {
            self::registerFailedLogin($row);
            IpGuard::recordFailedLogin();
            return null;
        }
        return $row;
    }

    /** Finish signing in a user whose password (and two-step code, if on) checked out. */
    public static function completeLogin(array $row): void
    {
        self::clearFailedLogins((int) $row['id']);
        Session::regenerate();
        unset($_SESSION['2fa_pending']);
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['sv'] = (int) ($row['session_version'] ?? 0);
        self::$user = $row;
        self::$loaded = true;
    }

    /**
     * One-shot sign-in for flows that just proved the password themselves
     * (set-password link). Refuses accounts with two-step sign-in on — those
     * must go through /login so the code is asked for.
     */
    public static function attempt(string $email, string $password): bool
    {
        $row = self::checkPassword($email, $password);
        if ($row === null || \App\Domain\TwoFactorService::isEnabled($row)) {
            return false;
        }
        self::completeLogin($row);
        return true;
    }

    /** True while the account's login lockout (set after too many failures) has not yet expired. */
    public static function isLocked(array $row): bool
    {
        return $row['login_locked_until'] && strtotime($row['login_locked_until']) > time();
    }

    /**
     * Re-check the signed-in person's password (My account changes). A wrong
     * password counts toward the same lockout as sign-in, so a hijacked
     * session can't be used to guess it.
     */
    public static function verifyCurrentPassword(array $user, string $password): bool
    {
        $stmt = Db::conn()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $user['id']]);
        $row = $stmt->fetch();
        if (!$row || self::isLocked($row) || $row['password_hash'] === null || !password_verify($password, $row['password_hash'])) {
            if ($row) {
                self::registerFailedLogin($row);
            }
            return false;
        }
        return true;
    }

    /** A wrong two-step code counts toward the sign-in lockout too. */
    public static function registerFailedSecondStep(int $userId): void
    {
        $stmt = Db::conn()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        if ($row = $stmt->fetch()) {
            self::registerFailedLogin($row);
        }
        IpGuard::recordFailedLogin();
    }

    /**
     * Sign out every other session for this user (password change, two-step
     * changes, admin reset). Sessions carry the version they started under;
     * user() drops any that no longer match. $keepCurrent re-stamps the
     * caller's own session so they stay signed in.
     */
    public static function bumpSessionVersion(int $userId, bool $keepCurrent): void
    {
        Db::conn()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = :id')->execute(['id' => $userId]);
        if ($keepCurrent && (int) ($_SESSION['user_id'] ?? 0) === $userId) {
            $stmt = Db::conn()->prepare('SELECT session_version FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $_SESSION['sv'] = (int) $stmt->fetchColumn();
            Session::regenerate();
        }
        self::$loaded = false;
    }

    private static function registerFailedLogin(array $row): void
    {
        $pdo = Db::conn();
        $attempts = (int) $row['failed_login_attempts'] + 1;
        $maxAttempts = (int) Config::get('security.login_max_attempts', 5);
        $lockedUntil = null;
        if ($attempts >= $maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', time() + (int) Config::get('security.login_lockout_seconds', 900));
            $attempts = 0;
        }
        $stmt = $pdo->prepare('UPDATE users SET failed_login_attempts = :a, login_locked_until = :l WHERE id = :id');
        $stmt->execute(['a' => $attempts, 'l' => $lockedUntil, 'id' => $row['id']]);
    }

    private static function clearFailedLogins(int $userId): void
    {
        $stmt = Db::conn()->prepare('UPDATE users SET failed_login_attempts = 0, login_locked_until = NULL WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /** Forget the cached user and destroy the session. */
    public static function logout(): void
    {
        self::$user = null;
        self::$loaded = false;
        Session::destroy();
    }

    /** Whether anyone is signed in with a valid, current session. */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * The signed-in user's row (cached per request), or null if signed out,
     * deactivated, or the session predates a security change (session_version).
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return self::$user = null;
        }
        $stmt = Db::conn()->prepare('SELECT * FROM users WHERE id = :id AND active = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        // Signed out elsewhere (password changed, admin reset…): this session is stale.
        if ($row && (int) ($row['session_version'] ?? 0) !== (int) ($_SESSION['sv'] ?? 0)) {
            unset($_SESSION['user_id'], $_SESSION['sv']);
            return self::$user = null;
        }
        return self::$user = ($row ?: null);
    }

    /** Signed-in user's id, or null. */
    public static function id(): ?int
    {
        return self::user() ? (int) self::user()['id'] : null;
    }

    /** Signed-in user's role: admin, ed, bookkeeper or staff (null if signed out). */
    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    /** True for the admin role only. */
    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** True for plain staff, who only ever see their own requests. */
    public static function isStaff(): bool
    {
        return self::role() === 'staff';
    }

    /** ED or admin — can sign off on approvals. */
    public static function isApprover(): bool
    {
        return in_array(self::role(), ['ed', 'admin'], true);
    }

    /** Bookkeeper or admin — sees every request (to enter in QuickBooks and print) and manages staff accounts. */
    public static function isBookkeeperRole(): bool
    {
        return in_array(self::role(), ['bookkeeper', 'admin'], true);
    }

    /** Verify a user's sign-off PIN, honoring the 5-attempt lockout (build-notes §6). */
    public static function verifyPin(array $user, string $pin): bool
    {
        if ($user['pin_locked_until'] && strtotime($user['pin_locked_until']) > time()) {
            return false;
        }
        if ($user['signoff_pin'] === null || !password_verify($pin, $user['signoff_pin'])) {
            self::registerFailedPin($user);
            return false;
        }
        self::clearFailedPin((int) $user['id']);
        return true;
    }

    private static function registerFailedPin(array $row): void
    {
        $pdo = Db::conn();
        $attempts = (int) $row['failed_pin_attempts'] + 1;
        $maxAttempts = (int) Config::get('security.pin_max_attempts', 5);
        $lockedUntil = null;
        if ($attempts >= $maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', time() + (int) Config::get('security.pin_lockout_seconds', 900));
            $attempts = 0;
        }
        $stmt = $pdo->prepare('UPDATE users SET failed_pin_attempts = :a, pin_locked_until = :l WHERE id = :id');
        $stmt->execute(['a' => $attempts, 'l' => $lockedUntil, 'id' => $row['id']]);
    }

    private static function clearFailedPin(int $userId): void
    {
        $stmt = Db::conn()->prepare('UPDATE users SET failed_pin_attempts = 0, pin_locked_until = NULL WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }
}

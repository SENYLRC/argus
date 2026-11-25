<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use PDO;

/** Plain data access for the users table. Role/permission rules live in the controllers. */
final class UserRepository
{
    /** Every account, grouped admin, ED, bookkeeper, staff, then by name. */
    public static function all(): array
    {
        return Db::conn()->query('SELECT * FROM users ORDER BY FIELD(role,\'admin\',\'ed\',\'bookkeeper\',\'staff\'), name')
            ->fetchAll();
    }

    /** One account by id, or null. */
    public static function find(int $id): ?array
    {
        $stmt = Db::conn()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** One account by login email, or null. */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Db::conn()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    /** Insert an active account with no password yet (the invite link sets it). Returns the new id. */
    public static function create(string $name, ?string $title, string $email, string $role): int
    {
        $stmt = Db::conn()->prepare(
            'INSERT INTO users (name, title, email, role, active) VALUES (:name, :title, :email, :role, 1)'
        );
        $stmt->execute(['name' => $name, 'title' => $title, 'email' => $email, 'role' => $role]);
        return (int) Db::conn()->lastInsertId();
    }

    /** Change name, title, email and role. */
    public static function update(int $id, string $name, ?string $title, string $email, string $role): void
    {
        $stmt = Db::conn()->prepare(
            'UPDATE users SET name = :name, title = :title, email = :email, role = :role WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'title' => $title, 'email' => $email, 'role' => $role, 'id' => $id]);
    }

    /** Store a password_hash() of the new password and stamp password_changed_at. */
    public static function setPassword(int $id, string $plainPassword): void
    {
        $stmt = Db::conn()->prepare('UPDATE users SET password_hash = :hash, password_changed_at = NOW(), force_pw_reset = 0 WHERE id = :id');
        $stmt->execute(['hash' => password_hash($plainPassword, PASSWORD_DEFAULT), 'id' => $id]);
    }

    /** Store the hashed 4-digit sign-off PIN and clear any PIN lockout. */
    public static function setPin(int $id, string $pin): void
    {
        $stmt = Db::conn()->prepare(
            'UPDATE users SET signoff_pin = :hash, failed_pin_attempts = 0, pin_locked_until = NULL WHERE id = :id'
        );
        $stmt->execute(['hash' => password_hash($pin, PASSWORD_DEFAULT), 'id' => $id]);
    }

    /**
     * Admin reset: clears the login password, sign-off PIN and two-step
     * sign-in — app, security keys, recovery codes (the lost-phone/key route), and signs out every session. A fresh
     * set-password token must be issued after this.
     */
    public static function clearCredentials(int $id): void
    {
        $stmt = Db::conn()->prepare(
            'UPDATE users SET password_hash = NULL, signoff_pin = NULL,
                failed_login_attempts = 0, login_locked_until = NULL,
                failed_pin_attempts = 0, pin_locked_until = NULL,
                totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL,
                session_version = session_version + 1
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        Db::conn()->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute(['id' => $id]);
        SecurityKeyService::removeAll($id); // security keys go too (lost-key route)
    }

    /** Activate or deactivate (deactivated people can't sign in; history is kept). */
    public static function setActive(int $id, bool $active): void
    {
        $stmt = Db::conn()->prepare('UPDATE users SET active = :active WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    /** Delete is admin-only and blocked for anyone who has ever signed off on a request (build-notes §2b). */
    public static function hasSignoffs(int $id): bool
    {
        $stmt = Db::conn()->prepare('SELECT COUNT(*) FROM signoffs WHERE user_id = :id');
        $stmt->execute(['id' => $id]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /** Hard-delete an account. Callers must check hasSignoffs() first. */
    public static function delete(int $id): void
    {
        $stmt = Db::conn()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Account count per role (zero-filled), for the role guide strip. */
    public static function counts(): array
    {
        $rows = Db::conn()->query('SELECT role, COUNT(*) c FROM users GROUP BY role')->fetchAll();
        $counts = ['admin' => 0, 'ed' => 0, 'bookkeeper' => 0, 'staff' => 0];
        foreach ($rows as $row) {
            $counts[$row['role']] = (int) $row['c'];
        }
        return $counts;
    }
}

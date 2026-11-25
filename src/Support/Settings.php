<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Admin-editable settings stored in the `settings` table (db/migrations/001).
 * Unlike config/config.php these change at runtime from the Administration
 * screen and survive re-copying the code onto the server.
 */
final class Settings
{
    private static ?array $cache = null;

    /** Read one setting (cached per request); $default if unset or the table doesn't exist yet. */
    public static function get(string $name, string $default = ''): string
    {
        if (self::$cache === null) {
            try {
                self::$cache = Db::conn()->query('SELECT name, value FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
            } catch (\PDOException) {
                // Table missing (migration not applied yet) — fall back to defaults.
                self::$cache = [];
            }
        }
        return array_key_exists($name, self::$cache) ? (string) self::$cache[$name] : $default;
    }

    /** Drop the per-request cache so the next get() reads the database again. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Insert or update one setting and clear the cache. */
    public static function set(string $name, string $value): void
    {
        Db::conn()->prepare(
            'INSERT INTO settings (name, value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute(['name' => $name, 'value' => $value]);
        self::$cache = null;
    }

    // ── Email test mode (after linx: SEAL_Functions::email_mode) ──────────────

    /**
     * '' send normally, 'redirect' every email to the test address(es), 'off'
     * send nothing (logged only). A missing setting counts as 'off' — failing
     * safe, so a half-migrated install can't start emailing real people.
     */
    public static function emailMode(): string
    {
        $mode = self::get('email_mode', 'off');
        return in_array($mode, ['', 'redirect', 'off'], true) ? $mode : 'off';
    }

    /** Valid addresses from the comma-separated test address setting. */
    public static function emailTestTo(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', self::get('email_test_to'))),
            static fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    /** One-line warning for the admin bar and flash messages; '' when sending normally. */
    public static function emailModeText(): string
    {
        return match (self::emailMode()) {
            'off'      => 'Email test mode is ON: the system is not sending any email.',
            'redirect' => 'Email test mode is ON: all email goes to ' . (implode(', ', self::emailTestTo()) ?: '(no valid test address set — nothing is sent)') . ' instead of the real recipients.',
            default    => '',
        };
    }

    // ── Site banner ──────────────────────────────────────────────────────────

    /** Banner text shown to everyone, or '' when the banner is off. */
    public static function siteBanner(): string
    {
        return self::get('site_banner_on') === '1' ? trim(self::get('site_banner_text')) : '';
    }

    // ── Email options ────────────────────────────────────────────────────────

    /** Per-notice switch; templates without a switch (set-password) are always on. */
    public static function noticeEnabled(string $template): bool
    {
        return self::get('notify_' . $template, '1') !== '0';
    }

    /** Sender address: the admin setting, falling back to config mail.from_email. */
    public static function mailFromEmail(): string
    {
        return self::get('mail_from_email') ?: (string) Config::get('mail.from_email');
    }

    /** Sender display name: the admin setting, falling back to config mail.from_name. */
    public static function mailFromName(): string
    {
        return self::get('mail_from_name') ?: (string) Config::get('mail.from_name');
    }
}

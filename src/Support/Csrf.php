<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session-bound CSRF token, checked on every POST (build-notes §6).
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    /** The session's CSRF token, created on first use. */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Render as a hidden form field. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    /** Constant-time comparison of a submitted token against the session's. */
    public static function check(?string $submitted): bool
    {
        if (!is_string($submitted) || empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $submitted);
    }
}

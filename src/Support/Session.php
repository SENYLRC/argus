<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session bootstrap and idle-timeout enforcement (build-notes §6):
 * httponly + secure + SameSite=Lax cookies, id regenerated on login,
 * 30-minute idle timeout.
 */
final class Session
{
    /** Start the session with hardened cookie settings and enforce the idle timeout. Safe to call twice. */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // use_strict_mode: the session module rejects an uninitialized id supplied
        // by the client rather than starting a new session under it — closes a
        // session-fixation route where an attacker sets a known id on the victim's
        // cookie before they log in. use_only_cookies: never accept a session id
        // from the URL. Both usually match php.ini's own defaults; set explicitly
        // so this doesn't depend on the server's ini file being correctly locked
        // down too.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => !Config::isDevelopment(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        self::enforceIdleTimeout();
    }

    private static function enforceIdleTimeout(): void
    {
        $timeout = (int) Config::get('security.session_idle_timeout', 1800);
        $lastSeen = $_SESSION['_last_seen'] ?? null;

        if ($lastSeen !== null && (time() - $lastSeen) > $timeout) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        }

        $_SESSION['_last_seen'] = time();
    }

    /** Call on every successful login to prevent session fixation. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Empty the session, expire its cookie and destroy it (logout). */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

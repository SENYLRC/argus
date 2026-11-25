<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Thin wrapper around config/config.php. Loaded once per request.
 *
 * Server-specific values (database host/name/user/password, and optionally
 * base URL, SMTP, …) live in a `.env` file at the app root — server-only,
 * written by deploy/install.sh, never in the dev tree or git — so copying a
 * fresh tree onto the server can't clobber them. config.php reads them with
 * getenv(); real environment variables win over .env.
 *
 * Legacy: config/secrets.php (the previous mechanism) is still merged on top
 * if present; install.sh moves its password into .env and deletes it.
 */
final class Config
{
    private static ?array $values = null;

    /** Read .env, then config/config.php (and legacy secrets.php); throws if config.php is missing. */
    public static function load(?string $path = null): void
    {
        $path ??= dirname(__DIR__, 2) . '/config/config.php';
        self::loadEnv(dirname($path, 2) . '/.env');
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Missing {$path} — copy config/config.example.php to config/config.php and fill in real values."
            );
        }
        $values = require $path;
        $secrets = dirname($path) . '/secrets.php';
        if (is_file($secrets)) {
            $values = array_replace_recursive($values, require $secrets);
        }
        self::$values = $values;
    }

    /**
     * Minimal .env reader: KEY=value lines, # comments, optional single or
     * double quotes around the value. Doesn't override variables already set
     * in the real environment. Deliberately tiny — no interpolation.
     */
    public static function loadEnv(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !preg_match('/^(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            [, $key, $value] = $m;
            if (preg_match('/^"(.*)"$/s', $value, $q)) {
                $value = stripcslashes($q[1]);
            } elseif (preg_match("/^'(.*)'$/s", $value, $q)) {
                $value = $q[1];
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value)); // trailing comment on an unquoted value
            }
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    /** Look up a value by dotted path, e.g. get('db.dsn'); returns $default if any segment is missing. */
    public static function get(string $dottedKey, mixed $default = null): mixed
    {
        if (self::$values === null) {
            self::load();
        }
        $node = self::$values;
        foreach (explode('.', $dottedKey) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /** True when app.env is 'development' (shows errors, allows non-HTTPS cookies). */
    public static function isDevelopment(): bool
    {
        return self::get('app.env') === 'development';
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal QuickBooks Online Accounting API client (OAuth 2.0 + query), used
 * only to READ classes, accounts and budgets (App\Domain\QuickBooksSync).
 * Intuit has no read-only scope; this app never sends a write request.
 *
 * Tokens: access tokens last an hour; refresh tokens ROTATE on every refresh
 * (roughly daily) with a fixed maximum life of about five years — Intuit
 * reports the expiry with each refresh. Reusing an already-rotated refresh
 * token revokes the whole connection, so every refresh happens under a MySQL
 * named lock and re-reads the stored token first; the newest token is saved
 * before it's used. Tokens are stored encrypted (Crypto / APP_KEY) in the
 * settings table.
 */
final class QuickBooksClient
{
    private const AUTH_URL   = 'https://appcenter.intuit.com/connect/oauth2';
    private const TOKEN_URL  = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    private const REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
    private const SCOPE      = 'com.intuit.quickbooks.accounting';
    private const MINOR_VERSION = '75';
    private const PAGE_SIZE  = 1000; // the API's maximum per query page

    /** True when Intuit client id/secret are set and APP_KEY exists to encrypt the tokens. */
    public static function configured(): bool
    {
        return Config::get('quickbooks.client_id') !== '' && Config::get('quickbooks.client_secret') !== '' && Crypto::available();
    }

    /** True once an admin has completed the OAuth flow (realm id and tokens are stored). */
    public static function isConnected(): bool
    {
        return Settings::get('qbo_realm_id') !== '' && Settings::get('qbo_tokens') !== '';
    }

    /** URL to send the admin to; $state is checked on the way back (CSRF). */
    public static function authorizeUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => Config::get('quickbooks.client_id'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'redirect_uri' => Config::get('quickbooks.redirect_uri'),
            'state' => $state,
        ]);
    }

    /** Finish connecting: swap the one-time code for tokens and store them. */
    public static function exchangeCode(string $code, string $realmId, string $connectedBy): void
    {
        $tokens = self::tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => Config::get('quickbooks.redirect_uri')]);
        Settings::set('qbo_realm_id', $realmId);
        Settings::set('qbo_connected_at', date('Y-m-d H:i:s'));
        Settings::set('qbo_connected_by', $connectedBy);
        self::saveTokens($tokens);
    }

    /** Revoke at Intuit (best effort) and forget the connection here. */
    public static function disconnect(): void
    {
        $tokens = self::loadTokens();
        if ($tokens) {
            try {
                self::http('POST', self::REVOKE_URL, [
                    'Authorization: Basic ' . self::basicAuth(), 'Accept: application/json', 'Content-Type: application/json',
                ], json_encode(['token' => $tokens['refresh']]));
            } catch (\RuntimeException) {
                // Already revoked or unreachable — still forget it locally.
            }
        }
        foreach (['qbo_tokens', 'qbo_realm_id', 'qbo_connected_at', 'qbo_connected_by', 'qbo_company_name'] as $k) {
            Settings::set($k, '');
        }
    }

    /** Connection details for the admin screen. */
    public static function status(): array
    {
        $t = self::loadTokens();
        return [
            'connected' => self::isConnected(),
            'realm_id' => Settings::get('qbo_realm_id'),
            'company' => Settings::get('qbo_company_name'),
            'connected_at' => Settings::get('qbo_connected_at'),
            'connected_by' => Settings::get('qbo_connected_by'),
            'refresh_expires_at' => $t ? date('Y-m-d', (int) $t['refresh_exp']) : null,
            'environment' => (string) Config::get('quickbooks.environment', 'production'),
        ];
    }

    /**
     * Run a QBO query ("SELECT * FROM Account WHERE …") and return every
     * matching entity, following pages. $entity is the result key ("Account").
     */
    public static function query(string $sql, string $entity): array
    {
        $all = [];
        for ($start = 1; ; $start += self::PAGE_SIZE) {
            $page = self::api('GET', '/query?' . http_build_query([
                'query' => $sql . ' STARTPOSITION ' . $start . ' MAXRESULTS ' . self::PAGE_SIZE,
                'minorversion' => self::MINOR_VERSION,
            ]));
            $rows = $page['QueryResponse'][$entity] ?? [];
            $all = array_merge($all, $rows);
            if (count($rows) < self::PAGE_SIZE) {
                return $all;
            }
        }
    }

    /** Company name (cached in settings for the status panel). */
    public static function companyName(): string
    {
        $info = self::query('SELECT * FROM CompanyInfo', 'CompanyInfo')[0] ?? [];
        $name = (string) ($info['CompanyName'] ?? '');
        Settings::set('qbo_company_name', $name);
        return $name;
    }

    // ── internals ────────────────────────────────────────────────────────────

    private static function api(string $method, string $path): array
    {
        $realm = Settings::get('qbo_realm_id');
        if ($realm === '') {
            throw new \RuntimeException('QuickBooks is not connected.');
        }
        $base = Config::get('quickbooks.environment') === 'sandbox'
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
        return self::http($method, "{$base}/v3/company/" . rawurlencode($realm) . $path, [
            'Authorization: Bearer ' . self::accessToken(), 'Accept: application/json',
        ]);
    }

    /** A valid access token, refreshing (under a lock) when it's within 5 minutes of expiry. */
    private static function accessToken(): string
    {
        $tokens = self::loadTokens();
        if (!$tokens) {
            throw new \RuntimeException('QuickBooks is not connected.');
        }
        if ($tokens['access_exp'] > time() + 300) {
            return $tokens['access'];
        }
        $lock = Db::conn()->query("SELECT GET_LOCK('argus_qbo_tokens', 20)")->fetchColumn();
        if ((int) $lock !== 1) {
            throw new \RuntimeException('Another QuickBooks sync is refreshing the connection — try again in a moment.');
        }
        try {
            Settings::forget(); // re-read: another process may have just rotated it
            $tokens = self::loadTokens();
            if (!$tokens) {
                throw new \RuntimeException('QuickBooks is not connected.');
            }
            if ($tokens['access_exp'] <= time() + 300) {
                $tokens = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh']]);
                self::saveTokens($tokens);
            }
            return $tokens['access'];
        } finally {
            Db::conn()->query("SELECT RELEASE_LOCK('argus_qbo_tokens')");
        }
    }

    /** POST to Intuit's token endpoint; returns normalised tokens. */
    private static function tokenRequest(array $form): array
    {
        $r = self::http('POST', self::TOKEN_URL, [
            'Authorization: Basic ' . self::basicAuth(), 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($form));
        if (empty($r['access_token']) || empty($r['refresh_token'])) {
            throw new \RuntimeException('QuickBooks did not return tokens.');
        }
        return [
            'access' => $r['access_token'],
            'access_exp' => time() + (int) ($r['expires_in'] ?? 3600),
            'refresh' => $r['refresh_token'],
            'refresh_exp' => time() + (int) ($r['x_refresh_token_expires_in'] ?? 0),
        ];
    }

    private static function saveTokens(array $t): void
    {
        Settings::set('qbo_tokens', Crypto::encrypt(json_encode($t, JSON_THROW_ON_ERROR)));
    }

    private static function loadTokens(): ?array
    {
        $stored = Settings::get('qbo_tokens');
        if ($stored === '') {
            return null;
        }
        try {
            $t = json_decode(Crypto::decrypt($stored), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null; // APP_KEY changed or value damaged — reconnect needed
        }
        return is_array($t) && isset($t['access'], $t['refresh']) ? $t : null;
    }

    private static function basicAuth(): string
    {
        return base64_encode(Config::get('quickbooks.client_id') . ':' . Config::get('quickbooks.client_secret'));
    }

    /**
     * One HTTPS request; decoded JSON on 2xx, otherwise a RuntimeException
     * carrying Intuit's error message and its intuit_tid (for Intuit support).
     */
    private static function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException("Couldn't reach QuickBooks: {$err}");
        }
        $json = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = $json['Fault']['Error'][0]['Detail'] ?? $json['Fault']['Error'][0]['Message']
                ?? $json['error_description'] ?? $json['error'] ?? "HTTP {$status}";
            $tid = $responseHeaders['intuit_tid'] ?? '';
            if (($json['error'] ?? '') === 'invalid_grant') {
                $msg = 'The QuickBooks connection has expired or was revoked — reconnect it under Administration > QuickBooks.';
            }
            throw new \RuntimeException('QuickBooks: ' . $msg . ($tid !== '' ? " (intuit_tid {$tid})" : ''));
        }
        return is_array($json) ? $json : [];
    }
}

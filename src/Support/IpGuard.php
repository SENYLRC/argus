<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\AuditLogger;
use PDOException;

/**
 * IP protection, ported from the SENYLRC WordPress security plugin.
 *
 *  - Sign-in lockout: N failed sign-ins or two-step codes from one IP inside a
 *    time window locks that IP out of the sign-in pages (people already signed
 *    in are not affected).
 *  - Scanner lockout (optional): N "page not found" hits by someone who is not
 *    signed in locks that IP out of the whole site.
 *  - Allow list (never locked) and block list (always refused): IPv4 and IPv6,
 *    exact or CIDR, one per line.
 *
 * The office shares one public IP, so everyone counts toward the same totals:
 * the defaults are generous, and the per-account lockout in Auth is the main
 * brake. Counters live in ip_hits, lockouts in ip_bans (both in the database,
 * not the session, so they survive an attacker dropping cookies).
 *
 * Everything fails open: if the tables don't exist yet (migration 014 not
 * applied) or the database errors, nobody is locked out by this class.
 * Emergency off switch for a lockout that traps an admin: set
 * ARGUS_DISABLE_IP_GUARD=1 in the server's .env.
 */
final class IpGuard
{
    private const MSG_LOGIN = 'Too many failed sign-in attempts from your address. Try again later, or ask Admin / Tech to lift the lockout.';
    private const MSG_404   = 'Too many requests for pages that don’t exist came from your address, so access is paused. Try again later, or ask Admin / Tech.';
    private const MSG_BLOCK = 'Access from your address has been blocked by Admin / Tech.';

    /** Whether IP protection is on (setting) and not switched off by the emergency variable. */
    public static function enabled(): bool
    {
        $off = getenv('ARGUS_DISABLE_IP_GUARD');
        if ($off !== false && $off !== '' && $off !== '0') {
            return false;
        }
        return Settings::get('lp_enabled', '1') === '1';
    }

    /** True when $ip equals an address or falls inside a CIDR range (IPv4 or IPv6). */
    public static function ipMatches(string $ip, string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        if (!str_contains($entry, '/')) {
            return $ipBin === @inet_pton($entry);
        }
        [$net, $bits] = explode('/', $entry, 2);
        $netBin = @inet_pton($net);
        if ($netBin === false || strlen($ipBin) !== strlen($netBin) || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        if ($bits > strlen($netBin) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** True when $ip matches any line of an allow or block list. */
    public static function inList(string $ip, string $list): bool
    {
        foreach (preg_split('/\R/', $list) ?: [] as $line) {
            if (self::ipMatches($ip, $line)) {
                return true;
            }
        }
        return false;
    }

    /** Lines of a list that aren't a valid address or CIDR range (for form validation). */
    public static function invalidEntries(string $list): array
    {
        $bad = [];
        foreach (preg_split('/\R/', $list) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$addr, $bits] = array_pad(explode('/', $line, 2), 2, null);
            $v6 = str_contains($addr, ':');
            $ok = filter_var($addr, FILTER_VALIDATE_IP) !== false
                && ($bits === null || (ctype_digit($bits) && (int) $bits <= ($v6 ? 128 : 32)));
            if (!$ok) {
                $bad[] = $line;
            }
        }
        return $bad;
    }

    /**
     * The lock that applies to $ip right now, or null.
     * @return array{type:string,message:string}|null type is 'login', '404' or 'manual'
     */
    public static function lockFor(string $ip): ?array
    {
        if (!self::enabled() || self::inList($ip, Settings::get('lp_allowlist'))) {
            return null;
        }
        if (self::inList($ip, Settings::get('lp_blocklist'))) {
            return ['type' => 'manual', 'message' => self::MSG_BLOCK];
        }
        try {
            $stmt = Db::conn()->prepare(
                'SELECT kind FROM ip_bans WHERE ip = :ip AND (permanent = 1 OR expires_at > NOW()) ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['ip' => $ip]);
            $kind = $stmt->fetchColumn();
        } catch (PDOException) {
            return null;
        }
        return $kind ? ['type' => (string) $kind, 'message' => $kind === '404' ? self::MSG_404 : self::MSG_LOGIN] : null;
    }

    /**
     * Stop the request with HTTP 403 if this IP is locked. A sign-in lockout
     * only covers the sign-in and set-password pages, so someone already
     * signed in keeps working; scanner lockouts and the block list cover the
     * whole site.
     */
    public static function enforce(string $uri): void
    {
        $lock = self::lockFor(IpResolver::clientIp());
        if ($lock === null) {
            return;
        }
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($lock['type'] === 'login' && !str_starts_with($path, '/login') && !str_starts_with($path, '/set-password')) {
            return;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $lock['message'];
        exit;
    }

    /** Count a failed sign-in (wrong password, unknown email, wrong two-step code) against the caller's IP. */
    public static function recordFailedLogin(): void
    {
        if (!self::enabled()) {
            return;
        }
        self::hit('login', (int) Settings::get('lp_failed_logins', '10'), (int) Settings::get('lp_timeframe', '600'));
    }

    /** Count a "page not found" by someone not signed in, if scanner lockout is on. */
    public static function record404(): void
    {
        if (!self::enabled() || Settings::get('lp_404_enabled', '0') !== '1' || Auth::check()) {
            return;
        }
        self::hit('404', (int) Settings::get('lp_404_hits', '20'), (int) Settings::get('lp_404_timeframe', '300'));
    }

    /** Record one hit, and lock the IP when it reaches $limit hits inside $window seconds. */
    private static function hit(string $kind, int $limit, int $window): void
    {
        $ip = IpResolver::clientIp();
        $limit = max(1, $limit);
        $window = max(30, $window);
        if (self::inList($ip, Settings::get('lp_allowlist'))) {
            return;
        }
        try {
            $pdo = Db::conn();
            $pdo->prepare('INSERT INTO ip_hits (ip, kind) VALUES (:ip, :k)')->execute(['ip' => $ip, 'k' => $kind]);
            if (random_int(1, 50) === 1) {
                $pdo->exec('DELETE FROM ip_hits WHERE at < NOW() - INTERVAL 1 DAY');
            }
            $count = $pdo->prepare("SELECT COUNT(*) FROM ip_hits WHERE ip = :ip AND kind = :k AND at > NOW() - INTERVAL {$window} SECOND");
            $count->execute(['ip' => $ip, 'k' => $kind]);
            if ((int) $count->fetchColumn() >= $limit) {
                self::lock($ip, $kind);
            }
        } catch (PDOException) {
            // Fail open: counting problems must never block anyone.
        }
    }

    /** Lock an IP (for the configured time, or until lifted) and clear its counters. */
    private static function lock(string $ip, string $kind): void
    {
        $permanent = Settings::get('lp_permanent', '0') === '1';
        $minutes = max(1, (int) Settings::get('lp_duration', '15'));
        $pdo = Db::conn();
        if ($permanent) {
            $pdo->prepare('INSERT INTO ip_bans (ip, kind, permanent) VALUES (:ip, :k, 1)')->execute(['ip' => $ip, 'k' => $kind]);
        } else {
            $pdo->prepare("INSERT INTO ip_bans (ip, kind, permanent, expires_at) VALUES (:ip, :k, 0, NOW() + INTERVAL {$minutes} MINUTE)")
                ->execute(['ip' => $ip, 'k' => $kind]);
        }
        $pdo->prepare('DELETE FROM ip_hits WHERE ip = :ip AND kind = :k')->execute(['ip' => $ip, 'k' => $kind]);
        AuditLogger::log('security', null, 'ip_lockout',
            ($kind === '404' ? 'Scanner lockout' : 'Sign-in lockout') . " for {$ip}" . ($permanent ? ' (until lifted)' : " ({$minutes} min)"),
            actorId: null, actorEmail: 'system');
    }

    /** Lock-outs in force now, newest first. */
    public static function activeBans(): array
    {
        try {
            return Db::conn()->query(
                'SELECT id, ip, kind, permanent, expires_at, created_at FROM ip_bans
                 WHERE permanent = 1 OR expires_at > NOW() ORDER BY id DESC'
            )->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    /** Lift every lockout for an address and reset its counters. */
    public static function unlock(string $ip): void
    {
        $pdo = Db::conn();
        $pdo->prepare('DELETE FROM ip_bans WHERE ip = :ip')->execute(['ip' => $ip]);
        $pdo->prepare('DELETE FROM ip_hits WHERE ip = :ip')->execute(['ip' => $ip]);
    }
}

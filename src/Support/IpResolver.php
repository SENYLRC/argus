<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Client IP resolution per build-notes §2d: X-Forwarded-For is only trusted
 * when REMOTE_ADDR itself is a configured trusted proxy. With no proxy in
 * front (the README's vhost talks straight to PHP), REMOTE_ADDR is
 * authoritative and this simply returns it.
 */
final class IpResolver
{
    /** The caller's IP: REMOTE_ADDR, or the first X-Forwarded-For entry only when the direct peer is a configured trusted proxy. */
    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = Config::get('trusted_proxies', []);

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($remote, $trusted, true)) {
            // Walk the chain from the nearest hop, skipping our own proxies: the first address that
            // isn't one of them is the client. The left-most entries are whatever the client itself
            // claimed, so they can't be trusted (a forged one would dodge lockouts and the audit log).
            $chain = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                if (filter_var($chain[$i], FILTER_VALIDATE_IP) === false) {
                    break;
                }
                if (!in_array($chain[$i], $trusted, true)) {
                    return $chain[$i];
                }
            }
        }

        return $remote;
    }

    /** True for RFC1918 / loopback addresses, or anything inside a configured office-LAN CIDR. */
    public static function isOfficeLan(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        foreach (Config::get('office_lan_ranges', []) as $cidr) {
            if (self::ipInCidr($ip, trim($cidr))) {
                return true;
            }
        }
        return self::isRfc1918($ip);
    }

    private static function isRfc1918(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
        ];
        foreach ($ranges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '' || !str_contains($cidr, '/')) {
            return false;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}

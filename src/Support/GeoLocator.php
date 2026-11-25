<?php

declare(strict_types=1);

namespace App\Support;

use GeoIp2\Database\Reader;

/**
 * Local MaxMind GeoLite2 lookup, per build-notes §2d: nothing leaves the
 * server, lookup failures are always non-fatal (log the action with nulls),
 * and office-LAN / private addresses are labelled rather than "unresolved".
 *
 * Accuracy is roughly 50-100 miles and reflects the ISP, not the person —
 * every caller must present this as corroborating detail, never proof of
 * location (see the Administration screen's audit-log footnote).
 */
final class GeoLocator
{
    private static ?Reader $cityReader = null;
    private static ?Reader $asnReader = null;
    private static bool $cityLoadAttempted = false;
    private static bool $asnLoadAttempted = false;

    /**
     * @return array{city:?string,region:?string,country:?string,isp:?string,lat:?float,lon:?float}
     */
    public static function lookup(string $ip): array
    {
        $result = ['city' => null, 'region' => null, 'country' => null, 'isp' => null, 'lat' => null, 'lon' => null];

        if (IpResolver::isOfficeLan($ip)) {
            $result['city'] = 'Highland';
            $result['region'] = 'NY';
            $result['country'] = 'US';
            $result['isp'] = 'office LAN';
            return $result;
        }

        try {
            $reader = self::cityReader();
            if ($reader !== null) {
                $record = $reader->city($ip);
                $result['city'] = $record->city->name;
                $result['region'] = $record->mostSpecificSubdivision->isoCode;
                $result['country'] = $record->country->isoCode;
                $result['lat'] = $record->location->latitude;
                $result['lon'] = $record->location->longitude;
            }
        } catch (\Throwable) {
            // Private/unknown IP or missing database — leave nulls, never block the caller.
        }

        try {
            $asn = self::asnReader();
            if ($asn !== null) {
                $result['isp'] = $asn->asn($ip)->autonomousSystemOrganization;
            }
        } catch (\Throwable) {
            // Same: non-fatal.
        }

        return $result;
    }

    /** Human-readable one-liner for the audit log's IP column, e.g. "Kingston, NY, US · Spectrum". */
    public static function describe(array $geo): string
    {
        if ($geo['isp'] === 'office LAN') {
            return trim(($geo['city'] ?? '') . ', ' . ($geo['region'] ?? '') . ' · office LAN', ' ,·');
        }
        if (!$geo['city'] && !$geo['country']) {
            return 'Unresolved — outside GeoLite2 range';
        }
        $parts = array_filter([$geo['city'], $geo['region'], $geo['country']]);
        $line = implode(', ', $parts);
        if ($geo['isp']) {
            $line .= ' · ' . $geo['isp'];
        }
        return $line;
    }

    private static function cityReader(): ?Reader
    {
        if (!self::$cityLoadAttempted) {
            self::$cityLoadAttempted = true;
            $path = Config::get('geoip.city_db_path');
            if ($path && is_file($path)) {
                self::$cityReader = new Reader($path);
            }
        }
        return self::$cityReader;
    }

    private static function asnReader(): ?Reader
    {
        if (!self::$asnLoadAttempted) {
            self::$asnLoadAttempted = true;
            $path = Config::get('geoip.asn_db_path');
            if ($path && is_file($path)) {
                self::$asnReader = new Reader($path);
            }
        }
        return self::$asnReader;
    }
}

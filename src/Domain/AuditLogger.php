<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Auth;
use App\Support\Db;
use App\Support\GeoLocator;
use App\Support\IpResolver;

/**
 * Every mutating action writes one row here, with IP + resolved geo
 * (build-notes §2d). Failures here must never abort the action that
 * triggered them — geo lookups already degrade to nulls on their own, but a
 * DB error while logging is caught too, so a full disk on the audit table
 * can't block someone from approving a request.
 */
final class AuditLogger
{
    /**
     * Append one row to the audit log with the actor, client IP and geolocation.
     * Actor defaults to the signed-in user (or 'system' for cron); $before/$after are optional JSON snapshots.
     */
    public static function log(
        string $entity,
        ?int $entityId,
        string $action,
        string $detail,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
        ?string $actorEmail = null
    ): void {
        $actorId ??= Auth::id();
        $actorEmail ??= Auth::user()['email'] ?? 'system';
        $ip = IpResolver::clientIp();
        $geo = GeoLocator::lookup($ip);

        try {
            $stmt = Db::conn()->prepare(
                'INSERT INTO audit_log
                    (actor_id, actor_email, entity, entity_id, action, detail, before_json, after_json,
                     ip, geo_city, geo_region, geo_country, geo_isp, geo_lat, geo_lon, user_agent)
                 VALUES
                    (:actor_id, :actor_email, :entity, :entity_id, :action, :detail, :before_json, :after_json,
                     :ip, :geo_city, :geo_region, :geo_country, :geo_isp, :geo_lat, :geo_lon, :user_agent)'
            );
            $stmt->execute([
                'actor_id'    => $actorId,
                'actor_email' => $actorEmail,
                'entity'      => $entity,
                'entity_id'   => $entityId,
                'action'      => $action,
                'detail'      => $detail,
                'before_json' => $before !== null ? json_encode($before) : null,
                'after_json'  => $after !== null ? json_encode($after) : null,
                'ip'          => $ip,
                'geo_city'    => $geo['city'],
                'geo_region'  => $geo['region'],
                'geo_country' => $geo['country'],
                'geo_isp'     => $geo['isp'],
                'geo_lat'     => $geo['lat'],
                'geo_lon'     => $geo['lon'],
                'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (\Throwable) {
            // Non-fatal by design — see class docblock.
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * PDO singleton. Prepared statements only — every caller passes bound
 * parameters, never interpolates values into SQL (build-notes §6).
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** Shared PDO connection (exceptions on, real prepared statements, assoc fetch), opened on first use. */
    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            // TLS to RDS, verified against the CA bundle. PHP 8.4+ moved these
            // constants to Pdo\Mysql; the server runs 8.3, dev runs 8.5.
            $ca = Config::get('db.ssl_ca');
            if ($ca) {
                $mysql = class_exists(\Pdo\Mysql::class);
                $options[$mysql ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA] = $ca;
                $options[$mysql ? \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
            $pdo = new PDO(
                Config::get('db.dsn'),
                Config::get('db.user'),
                Config::get('db.password'),
                $options
            );
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /** Convenience: run $fn inside a transaction, rolling back on any exception. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::conn();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

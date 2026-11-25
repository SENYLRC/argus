<?php
/**
 * Copy this file to config/config.php and fill in real values there.
 * config/config.php is gitignored — never commit real credentials.
 */
return [
    'app' => [
        'base_url'    => getenv('APP_BASE_URL') ?: 'https://argus.example.org',
        'env'         => getenv('APP_ENV') ?: 'production', // 'production' | 'development'
        // Encrypts two-step sign-in secrets at rest (App\Support\Crypto).
        // "base64:" + 32 random bytes, generated into .env by deploy/install.sh.
        'key'         => getenv('APP_KEY') ?: '',
        // In development, PHP errors are shown and cookies aren't forced to secure-only
        // so the app also works over plain http://localhost during testing.
    ],

    'db' => [
        // Amazon RDS (us-east-2). The web server reaches it across regions, so the
        // connection is TLS-verified against AWS's RDS CA bundle (ssl_ca below);
        // the app user is also created with REQUIRE SSL (deploy/install.sh).
        // Values come from /var/www/argus/.env on the server (see .env.example).
        'dsn'      => getenv('DB_DSN') ?: sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'your-database-host.example.com',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_NAME') ?: 'senylrc_expenses'
        ),
        'user'     => getenv('DB_USER') ?: 'senylrc_expenses',
        'password' => getenv('DB_PASSWORD') ?: 'change-me',
        // Set to null to disable TLS (only for a local dev MySQL).
        'ssl_ca'   => getenv('DB_SSL_CA') ?: dirname(__DIR__) . '/config/rds-global-bundle.pem',
    ],

    // QuickBooks Online (Administration > QuickBooks). Keys come from the app
    // created at developer.intuit.com and live in .env, never in this file.
    // The redirect URI must be registered on that Intuit app exactly as below.
    'quickbooks' => [
        'client_id'     => getenv('QBO_CLIENT_ID') ?: '',
        'client_secret' => getenv('QBO_CLIENT_SECRET') ?: '',
        'environment'   => getenv('QBO_ENVIRONMENT') ?: 'production', // 'sandbox' | 'production'
        'redirect_uri'  => getenv('QBO_REDIRECT_URI') ?: rtrim(getenv('APP_BASE_URL') ?: 'https://argus.example.org', '/') . '/admin/quickbooks/callback',
    ],

    'mail' => [
        // Local Postfix on the web server: plain SMTP on localhost:25, no auth.
        // For a remote provider instead, set username/password and 'tls' or 'ssl'.
        'host'        => getenv('SMTP_HOST') ?: 'localhost',
        'port'        => (int) (getenv('SMTP_PORT') ?: 25),
        'username'    => getenv('SMTP_USERNAME') ?: '',
        'password'    => getenv('SMTP_PASSWORD') ?: '',
        'encryption'  => getenv('SMTP_ENCRYPTION') ?: '', // '' | 'tls' | 'ssl'
        'from_email'  => 'expenses@example.org',
        'from_name'   => 'Southeastern NY Library Resources Council',
        'reply_to_ed' => true, // Reply-To is set to the current Executive Director's email at send time
    ],

    // Only trust X-Forwarded-For when REMOTE_ADDR is one of these (build-notes §2d).
    // Empty by default: with no reverse proxy in front (per README, the vhost is the
    // only thing between the browser and PHP), REMOTE_ADDR alone is authoritative.
    'trusted_proxies' => array_filter(explode(',', getenv('TRUSTED_PROXIES') ?: '')),

    // RFC1918 ranges that should resolve to "office LAN" instead of an unresolved
    // GeoLite2 lookup (build-notes §2d). Add the office's real subnet(s) here.
    'office_lan_ranges' => array_filter(explode(',', getenv('OFFICE_LAN_RANGES') ?: '192.0.2.0/24')),

    'geoip' => [
        'city_db_path' => getenv('GEOIP_CITY_DB') ?: '/usr/share/GeoIP/GeoLite2-City.mmdb',
        'asn_db_path'  => getenv('GEOIP_ASN_DB') ?: '/usr/share/GeoIP/GeoLite2-ASN.mmdb',
    ],

    'storage' => [
        // All outside the web root — see deploy/vhost.conf's <DirectoryMatch "/(\.git|storage)/">.
        'root'        => getenv('STORAGE_ROOT') ?: dirname(__DIR__) . '/storage',
        'attachments' => 'attachments',
        'signatures'  => 'signatures',
        'generated'   => 'generated_pdfs',
        'tmp'         => 'tmp',
    ],

    'uploads' => [
        'attachment_max_bytes' => 20 * 1024 * 1024,
        'signature_max_bytes'  => 2 * 1024 * 1024,
        'attachment_mimes'     => ['application/pdf', 'image/jpeg', 'image/png'],
        'signature_mimes'      => ['image/jpeg', 'image/png'],
    ],

    'security' => [
        'session_idle_timeout' => 30 * 60, // 30 minutes, build-notes §6
        'pin_max_attempts'     => 5,
        'pin_lockout_seconds'  => 15 * 60,
        'login_max_attempts'   => 5,
        'login_lockout_seconds'=> 15 * 60,
    ],

    'batch_print_cap' => 50, // build-notes §3 "cap ~50 per run"
];

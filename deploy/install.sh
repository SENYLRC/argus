#!/usr/bin/env bash
# Install / update Argus on the Ubuntu server. Run after every copy of the
# code onto the server — it's quick, and safe to repeat.
#
# Run as root from the copied tree:
#   cd /var/www/argus && bash deploy/install.sh             # normal update (and first install, with dev seed data)
#   cd /var/www/argus && bash deploy/install.sh --seed      # first install WITH the dev seed accounts (shared password, dev only)
#   cd /var/www/argus && bash deploy/install.sh --db        # force the database step (re-sync user, grants)
#
# Server settings live in /var/www/argus/.env (see .env.example): database
# host/name/user/password, base URL, optional SMTP. The first run creates it
# and generates the app's DB password; after that it's reused, never copied
# over by a code update.
#
# The database step needs the RDS admin (master) login and only runs when
# there's something to do: first install, a new db/migrations/*.sql file,
# the app login not working, or --db. A routine code update skips it and
# never prompts. The admin login is used for that run only — kept in a
# 0600 temp file deleted on exit, never written to .env. Every DB connection
# is TLS-verified against the RDS CA bundle.
#
# Never drops anything: schema loads only into an empty database, seed data
# only into an empty users table, each migration once.
set -euo pipefail

APP_DIR=/var/www/argus
# The account Apache/PHP runs this app as. On a server shared with other PHP sites, run Argus
# under its own user (a dedicated php-fpm pool) so those sites can't read .env; see the README.
WEB_USER="${WEB_USER:-www-data}"
SEED=0   # the seed creates accounts with a shared, documented dev password: opt in with --seed
FORCE_DB=0
for arg in "$@"; do
    case "$arg" in
        --seed)    SEED=1 ;;
        --no-seed) SEED=0 ;;
        --db)      FORCE_DB=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

say()  { printf '\n==> %s\n' "$*"; }
die()  { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "run as root"
[[ "$(pwd -P)" == "$APP_DIR" ]] || die "run from $APP_DIR (cd $APP_DIR first)"
[[ -f composer.json && -f db/schema.sql ]] || die "app files missing from $APP_DIR"

say "Checking PHP extensions and Apache modules"
# Capture the lists once, then search them. (Piping straight into `grep -q`
# can fail at random under `set -o pipefail`: grep exits on the first match,
# the writer gets SIGPIPE, and the pipeline reports failure.)
PHP_MODS="$(php -m)"
APACHE_MODS="$(apache2ctl -M 2>/dev/null || true)"
missing=()
for ext in pdo_mysql gd dom mbstring fileinfo json sodium; do
    grep -qix "$ext" <<<"$PHP_MODS" || missing+=("$ext")
done
(( ${#missing[@]} == 0 )) || die "missing PHP extensions: ${missing[*]}"
command -v composer >/dev/null || die "composer not installed (apt install composer)"
command -v mysql    >/dev/null || die "mysql client not installed (apt install mysql-client)"
for mod in rewrite ssl headers; do
    grep -q "^ ${mod}_module" <<<"$APACHE_MODS" || die "Apache module $mod not enabled (a2enmod $mod)"
done

say "Installing PHP dependencies"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction

say "Settings (.env)"
[[ -f config/config.php ]] || cp config/config.example.php config/config.php
# Create .env if it's missing or empty. The template is built in here rather
# than copied from .env.example, because copy tools often skip dotfiles.
if [[ ! -s .env ]]; then
    cat > .env <<'ENV'
# Argus — server-specific settings (written by deploy/install.sh).
# Never copy into the dev folder or commit. root:www-data 640.
DB_HOST=CHANGE_ME_DATABASE_HOST
DB_PORT=3306
DB_NAME=senylrc_expenses
DB_USER=senylrc_expenses
DB_PASSWORD=
APP_ENV=production
APP_BASE_URL=https://argus.example.org
# Mail defaults to the local Postfix on localhost:25. Uncomment to override:
# SMTP_HOST=localhost
# SMTP_PORT=25
# QuickBooks Online sync — keys from developer.intuit.com (see .env.example):
# QBO_CLIENT_ID=
# QBO_CLIENT_SECRET=
# QBO_ENVIRONMENT=production
ENV
    echo "Created .env"
else
    echo ".env exists — keeping it"
fi
grep -q 'CHANGE_ME_DATABASE_HOST' .env && die "Edit $APP_DIR/.env first: set DB_HOST (and APP_BASE_URL), then run this script again."
# Make sure it has a DB password: carry over the one from config/secrets.php
# (the earlier mechanism) if present, otherwise generate one.
if ! grep -qE '^DB_PASSWORD=.+' .env; then
    OLD_PASS=""
    if [[ -f config/secrets.php ]]; then
        OLD_PASS="$(php -r '$s = require "config/secrets.php"; echo $s["db"]["password"] ?? "";')"
    fi
    NEW_PASS="${OLD_PASS:-$(openssl rand -hex 24)}"
    if grep -q '^DB_PASSWORD=' .env; then
        sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${NEW_PASS}|" .env
    else
        printf 'DB_PASSWORD=%s\n' "$NEW_PASS" >> .env
    fi
    echo "Set DB_PASSWORD in .env$([[ -n "$OLD_PASS" ]] && echo ' (carried over from config/secrets.php)' || echo ' (newly generated)')"
    unset OLD_PASS NEW_PASS
fi
# Encryption key for two-step sign-in secrets. Generated once; changing it
# later makes existing two-step setups unreadable (people would re-enrol).
if ! grep -qE '^APP_KEY=base64:.+' .env; then
    sed -i '/^APP_KEY=/d' .env
    printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" >> .env
    echo "Generated APP_KEY in .env"
fi
if [[ -f config/secrets.php ]]; then
    rm -f config/secrets.php
    echo "Removed config/secrets.php (its password now lives in .env)"
fi
chown root:"$WEB_USER" .env && chmod 640 .env

# Read the effective settings the same way the app does (.env + config.php).
cfg() { php -r 'require "vendor/autoload.php"; App\Support\Config::load(); echo App\Support\Config::get($argv[1]);' "$1"; }
DSN="$(cfg db.dsn)"
DB_HOST="$(sed -nE 's/.*host=([^;]+).*/\1/p' <<<"$DSN")"
DB_NAME="$(sed -nE 's/.*dbname=([^;]+).*/\1/p' <<<"$DSN")"
DB_USER="$(cfg db.user)"
DB_PASS="$(cfg db.password)"
SSL_CA="$(cfg db.ssl_ca)"
[[ -n "$DB_HOST" && -n "$DB_NAME" && -n "$DB_USER" ]] || die "couldn't read DB host/name/user from .env / config"
[[ -n "$DB_PASS" && "$DB_PASS" != "change-me" ]] || die "DB_PASSWORD in .env is empty"
echo "Database: ${DB_NAME} on ${DB_HOST} as ${DB_USER}"

if [[ -n "$SSL_CA" && ! -s "$SSL_CA" ]]; then
    say "Downloading the Amazon RDS CA bundle"
    curl -fsSL https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem -o "$SSL_CA"
fi

# Does the database need attention? Checked with the app's own login, so a
# routine update never needs the admin password.
DB_STATE="$(php -r '
    require "vendor/autoload.php";
    try { $pdo = App\Support\Db::conn(); } catch (Throwable $e) { echo "noconnect"; exit; }
    try { $done = $pdo->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { echo "pending"; exit; }
    foreach (glob("db/migrations/*.sql") as $f) { if (!in_array(basename($f), $done, true)) { echo "pending"; exit; } }
    echo "ok";
' 2>/dev/null || echo noconnect)"

SEEDED=0
if [[ "$DB_STATE" == "ok" && $FORCE_DB -eq 0 ]]; then
    say "Database is up to date — skipping the database step (use --db to force it)"
else
    case "$DB_STATE" in
        noconnect) say "The app can't log in to the database yet (first install, or the password changed) — setting it up" ;;
        pending)   say "New database migrations to apply" ;;
        *)         say "Database step (--db)" ;;
    esac

    read -rp  "RDS admin user: " RDS_ADMIN
    read -rsp "RDS admin password: " RDS_ADMIN_PASS; echo
    ADMIN_CNF="$(mktemp)"
    trap 'rm -f "$ADMIN_CNF"' EXIT
    chmod 600 "$ADMIN_CNF"
    {
        echo "[client]"
        echo "host=$DB_HOST"
        echo "user=$RDS_ADMIN"
        echo "password=\"$RDS_ADMIN_PASS\""
        if [[ -n "$SSL_CA" ]]; then
            echo "ssl-ca=$SSL_CA"
            if [[ "$(mysql --version)" == *[Mm]ari[Dd][Bb]* ]]; then
                echo "ssl-verify-server-cert"
            else
                echo "ssl-mode=VERIFY_IDENTITY"
            fi
        fi
    } > "$ADMIN_CNF"
    unset RDS_ADMIN_PASS
    db() { mysql --defaults-extra-file="$ADMIN_CNF" "$@"; }
    db -e 'SELECT 1' >/dev/null || die "can't connect to $DB_HOST — check the admin login, and that the RDS security group allows this server on 3306"

    db -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    # '%' because the web server connects over the network; the RDS security
    # group limits who can reach 3306, and REQUIRE SSL refuses plaintext.
    # Always (re)set the password from .env, so a re-run heals any drift.
    db -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}' REQUIRE SSL"
    db -e "ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}' REQUIRE SSL"

    if [[ -z "$(db -N -e "SHOW TABLES FROM \`${DB_NAME}\` LIKE 'users'")" ]]; then
        db "$DB_NAME" < db/schema.sql
        echo "Schema loaded"
    else
        echo "Schema already present — skipping"
    fi

    # Migrations: db/migrations/NNN_*.sql, each applied once in order and
    # recorded in schema_migrations.
    db "$DB_NAME" -e "CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    for f in db/migrations/*.sql; do
        [[ -e "$f" ]] || continue
        m="$(basename "$f")"
        if [[ -z "$(db -N "$DB_NAME" -e "SELECT 1 FROM schema_migrations WHERE name = '${m}'")" ]]; then
            db "$DB_NAME" < "$f"
            db "$DB_NAME" -e "INSERT INTO schema_migrations (name) VALUES ('${m}')"
            echo "Applied migration $m"
        fi
    done

    # Least privilege, table by table: data rights only (no DDL/DROP/GRANT),
    # and the audit trail is append-only for the app — SELECT + INSERT on
    # audit_log, no UPDATE/DELETE. Re-applied each time so new tables get grants.
    db -e "REVOKE ALL PRIVILEGES ON \`${DB_NAME}\`.* FROM '${DB_USER}'@'%'" 2>/dev/null || true
    for t in $(db -N "$DB_NAME" -e "SHOW TABLES"); do
        db -e "REVOKE ALL PRIVILEGES ON \`${DB_NAME}\`.\`${t}\` FROM '${DB_USER}'@'%'" 2>/dev/null || true
        if [[ "$t" == "audit_log" ]]; then
            db -e "GRANT SELECT, INSERT ON \`${DB_NAME}\`.\`${t}\` TO '${DB_USER}'@'%'"
        else
            db -e "GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.\`${t}\` TO '${DB_USER}'@'%'"
        fi
    done
    echo "Grants applied (audit_log is append-only for ${DB_USER})"

    if (( SEED )) && [[ "$(db -N -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.users")" == "0" ]]; then
        db "$DB_NAME" < db/seed.sql
        SEEDED=1
        echo "Seed data loaded (DEV-ONLY credentials — see README)"
    fi
fi

say "Permissions"
chown -R root:"$WEB_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} +
find "$APP_DIR" -type f -exec chmod 640 {} +
chmod 750 bin/console deploy/install.sh
for d in attachments signatures generated_pdfs tmp branding; do
    mkdir -p "storage/$d"
done
# Compiled templates from the previous copy of the code — rebuilt on next load.
rm -rf storage/tmp/twig_cache
chown -R "$WEB_USER":"$WEB_USER" storage
find storage -type d -exec chmod 770 {} +
find storage -type f -exec chmod 660 {} +
chmod 640 .env config/config.php   # root:www-data — readable by Apache, not world

if (( SEEDED )); then
    say "Placeholder signatures"
    sudo -u "$WEB_USER" php bin/console signatures:seed-placeholders
fi

say "File integrity baseline"
# A deploy is a deliberate change: accept the files as they are now, so the
# nightly scan only reports changes made after this point.
sudo -u "$WEB_USER" php bin/console files:baseline || echo "Baseline skipped (database not ready?) — run: sudo -u $WEB_USER php bin/console files:baseline"

say "Cron"
sed "s/  www-data  /  ${WEB_USER}  /" deploy/senylrc-expenses.cron > /etc/cron.d/senylrc-expenses
chown root:root /etc/cron.d/senylrc-expenses && chmod 644 /etc/cron.d/senylrc-expenses

say "Apache"
if apache2ctl configtest; then
    systemctl reload apache2
else
    die "apache2ctl configtest failed — NOT reloading (other sites stay up on the old config)"
fi

say "Done"
echo "Browse to the site from an address your Apache Require ip line allows."
if (( SEEDED )); then
    echo "Seed logins share a documented dev password and PIN (see README) — reset them before go-live."
fi
if (( ! SEED )) && [[ "$DB_STATE" != "ok" ]]; then
    echo "Create the first admin: sudo -u $WEB_USER php bin/console user:create-admin you@example.org \"Your Name\""
fi
exit 0

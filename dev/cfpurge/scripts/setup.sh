#!/bin/bash
# One-time setup of the local test site. Run from dev/cfpurge:
#   docker compose exec web bash /dev-scripts/setup.sh
# Idempotent: steps already done are skipped.
set -e
cd /var/www/html

# Refuse to run on an incomplete site (Joomla still being extracted by entrypoint.sh).
if [ ! -f .dev-extracted ] || [ ! -f index.php ] || [ ! -f libraries/src/Version.php ]; then
    echo "!! Joomla is not completely extracted yet. Wait for 'AH00163' in 'docker compose logs web' and retry." >&2
    exit 1
fi
if [ ! -f configuration.php ] && [ ! -f installation/joomla.php ]; then
    echo "!! Incomplete site: no configuration.php and no installation/ folder. Recreate it with 'docker compose down -v'." >&2
    exit 1
fi

ADMIN_USER=admin
ADMIN_PASS="${DEV_ADMIN_PASSWORD:?DEV_ADMIN_PASSWORD missing, see .env.example}"
DB_PASS="${DEV_DB_PASSWORD:?DEV_DB_PASSWORD missing, see .env.example}"
DB_PREFIX=jdev_
SQL() { php /dev-scripts/sql.php "$1"; }

if [ ! -f configuration.php ]; then
    echo "== Installing Joomla"
    php installation/joomla.php install \
        --site-name="Cfpurge Dev" \
        --admin-user="Dev Admin" \
        --admin-username="$ADMIN_USER" \
        --admin-password="$ADMIN_PASS" \
        --admin-email="admin@example.test" \
        --db-type=mysqli --db-host=db --db-user=joomla --db-pass="$DB_PASS" \
        --db-name=joomla --db-prefix="$DB_PREFIX" --db-encryption=0 -n
    rm -rf installation
    cp htaccess.txt .htaccess
    php cli/joomla.php config:set sef_rewrite=true live_site="$DEV_SITE_URL" debug=false error_reporting=maximum log_path=/var/www/html/administrator/logs -n || true
    chown -R www-data:www-data /var/www/html
fi

JMAJOR=$(php -r 'define("_JEXEC", 1); require "libraries/src/Version.php"; echo Joomla\CMS\Version::MAJOR_VERSION;')
if [ ! -d administrator/language/it-IT ] && [ "$JMAJOR" != "6" ]; then
    echo "== Joomla $JMAJOR: skipping the Italian language pack (only the Joomla 6.2 pack is pinned here)"
elif [ ! -d administrator/language/it-IT ]; then
    echo "== Installing the official Italian language pack (to test the it-IT strings)"
    curl -sL -o tmp/it-IT.zip "https://downloads.joomla.org/language-packs/translations-joomla6/downloads/joomla6-italian/6-2-0-1/it-it_joomla_lang_full_6-2-0v1-zip?format=zip"
    chown www-data tmp/it-IT.zip
    su -s /bin/bash www-data -c "php cli/joomla.php extension:install --path=/var/www/html/tmp/it-IT.zip -n" || true
fi

echo "== Disabling guided tours autostart (it redirects automated backend tests)"
SQL "UPDATE ${DB_PREFIX}extensions SET enabled=0 WHERE folder='system' AND element IN ('guidedtours','stats')"

echo "== Enabling Web Services basic auth (local seeding only)"
SQL "UPDATE ${DB_PREFIX}extensions SET enabled=1 WHERE folder IN ('api-authentication','webservices') AND element IN ('basic','content','menus','plugins','installer')"

echo "== Done. Site: $DEV_SITE_URL  Admin: $DEV_SITE_URL/administrator ($ADMIN_USER)"

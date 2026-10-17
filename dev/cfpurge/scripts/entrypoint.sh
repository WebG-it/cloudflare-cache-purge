#!/bin/bash
# Extracts the official Joomla package (6.2.0 by default, see JOOMLA_ZIP) into the site volume on first start,
# then runs Apache in the foreground.
set -e

# The marker file is written only after a complete extraction: setup.sh refuses to run
# without it, so a half-extracted site is never installed.
if [ ! -f /var/www/html/.dev-extracted ]; then
    if [ ! -f /joomla.zip ]; then
        echo "Missing the Joomla package (.cache/joomla-6.2.0.zip or JOOMLA_ZIP, see docs/yooarchive/DEVELOPING.md)" >&2
        exit 1
    fi
    echo "Extracting Joomla into /var/www/html"
    php -r '$z = new ZipArchive(); if ($z->open("/joomla.zip") !== true) { exit(1); } if (!$z->extractTo("/var/www/html")) { exit(1); } $z->close();'
    chown -R www-data:www-data /var/www/html
    touch /var/www/html/.dev-extracted
    echo "Joomla extraction complete"
fi

# Show PHP errors in the log, never on screen.
cat > /usr/local/etc/php/conf.d/zz-dev.ini <<'INI'
display_errors=Off
log_errors=On
error_log=/var/www/html/administrator/logs/php-error.log
error_reporting=E_ALL
memory_limit=512M
upload_max_filesize=64M
post_max_size=64M
INI

exec apache2-foreground

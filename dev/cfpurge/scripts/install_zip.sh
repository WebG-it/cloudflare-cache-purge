#!/bin/bash
# Installs an extension package that is already inside the container (dev-only).
#   bash /dev-scripts/install_zip.sh /var/www/html/tmp/file.zip
set -e
cd /var/www/html
chown www-data "$1"
su -s /bin/bash www-data -c "php cli/joomla.php extension:install --path=$1 -n"

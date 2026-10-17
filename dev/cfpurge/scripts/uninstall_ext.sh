#!/bin/bash
# Uninstalls an extension by element/folder through the Joomla CLI installer.
# Dev-only tool.   bash /dev-scripts/uninstall_ext.sh plugin yooarchive system
set -e
cd /var/www/html
TYPE="$1"; ELEMENT="$2"; FOLDER="${3:-}"
WHERE="type='$TYPE' AND element='$ELEMENT'"
[ -n "$FOLDER" ] && WHERE="$WHERE AND folder='$FOLDER'"
ID=$(php /dev-scripts/sql.php "SELECT extension_id FROM jdev_extensions WHERE $WHERE" | tail -1 | tr -dc '0-9')
[ -n "$ID" ] || { echo "!! extension not found" >&2; exit 1; }
su -s /bin/bash www-data -c "php cli/joomla.php extension:remove $ID -n"

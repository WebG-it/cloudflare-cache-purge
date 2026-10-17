#!/bin/bash
# Dev-only: Joomla CLI update check as www-data.
cd /var/www/html
su -s /bin/bash www-data -c "php cli/joomla.php update:extensions:check -n"

#!/bin/bash
# Dev-only: makes https://api.cloudflare.com answer from /dev-mock (the FAKE Cloudflare API)
# inside the web container. Run once after the container is up (needs root, like setup.sh):
#   docker compose exec web bash /dev-scripts/mock_cf_setup.sh
# The docker-compose file maps api.cloudflare.com to 127.0.0.1 (extra_hosts); here Apache gets
# a TLS site for that name with a throw-away CA, and the CA is appended to the certificate
# bundles that PHP and Joomla use. Nothing here ever leaves the container.
set -e
SSL=/etc/ssl/cfmock
mkdir -p $SSL
if [ ! -f $SSL/ca.crt ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -keyout $SSL/ca.key -out $SSL/ca.crt -days 30 -subj "/CN=cfpurge-dev-test-ca" 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -keyout $SSL/api.key -out $SSL/api.csr -subj "/CN=api.cloudflare.com" 2>/dev/null
    printf "subjectAltName=DNS:api.cloudflare.com\n" > $SSL/san.cnf
    openssl x509 -req -in $SSL/api.csr -CA $SSL/ca.crt -CAkey $SSL/ca.key -CAcreateserial -out $SSL/api.crt -days 30 -extfile $SSL/san.cnf 2>/dev/null
fi

cat > /etc/apache2/sites-available/cfmock.conf <<'CONF'
<VirtualHost *:443>
    ServerName api.cloudflare.com
    DocumentRoot /dev-mock
    SSLEngine on
    SSLCertificateFile /etc/ssl/cfmock/api.crt
    SSLCertificateKeyFile /etc/ssl/cfmock/api.key
    <Directory /dev-mock>
        Require all granted
        FallbackResource /index.php
    </Directory>
    # PHP passes the Authorization header on only with this.
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
</VirtualHost>
CONF
a2enmod ssl >/dev/null
a2ensite cfmock >/dev/null

# Trust the throw-away CA: system store and the bundle shipped with Joomla's HTTP client.
cp $SSL/ca.crt /usr/local/share/ca-certificates/cfpurge-dev-test-ca.crt
update-ca-certificates >/dev/null 2>&1 || true
for bundle in $(find /var/www/html/libraries -name 'cacert.pem' 2>/dev/null); do
    grep -q cfpurge-dev-test-ca-marker "$bundle" || { echo "# cfpurge-dev-test-ca-marker" >> "$bundle"; cat $SSL/ca.crt >> "$bundle"; }
done
rm -f /tmp/cfmock.log; touch /tmp/cfmock.log; chmod 666 /tmp/cfmock.log
apache2ctl graceful
echo "Fake Cloudflare API ready on https://api.cloudflare.com (this container only)"

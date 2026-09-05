#!/bin/sh

MARKER="/var/www/html/data/.setup-done"

if [ -f "$MARKER" ]; then
    echo "Already installed. Skipping setup."
    exec supervisord -c /etc/supervisord.conf -n
fi

echo "=== EspoCRM auto-setup ==="

chown -R 82:82 /var/www/html/data /var/www/html/custom
chmod -R 777 /var/www/html/data /var/www/html/custom

rm -f /var/www/html/data/config.php
rm -f /var/www/html/data/config-internal.php
rm -f /var/www/html/data/config-internal-override.php
rm -f /var/www/html/data/config-state.php
rm -f /var/www/html/install/config.php
rm -f /var/www/html/data/cache/*

cd /var/www/html
php auto-setup.php 2>&1

chown -R 82:82 /var/www/html/data /var/www/html/custom
chmod -R 777 /var/www/html/data /var/www/html/custom

exec supervisord -c /etc/supervisord.conf -n

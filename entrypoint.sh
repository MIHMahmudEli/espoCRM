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
rm -f /var/www/html/install/config.php

cd /var/www/html

echo "[1/5] Saving database settings..."
cd /var/www/html/install
php cli.php -a saveSettings -d 'host-name=aws-0-ap-northeast-2.pooler.supabase.com:5432&db-name=postgres&db-user-name=postgres.nghztacvdcmizwjoaktf&db-user-password=MqjuNE5jJTDv9%26B&db-platform=Postgresql&user-lang=en_US' 2>&1

echo "[2/5] Building database schema..."
cd /var/www/html
php rebuild.php 2>&1

echo "[3/5] Creating admin user..."
cd /var/www/html/install
php cli.php -a createUser -d 'user-name=admin&user-pass=admin12%40%23' 2>&1

echo "[4/5] Saving preferences..."
php cli.php -a savePreferences -d 'language=en_US' 2>&1

echo "[5/5] Finalizing installation..."
php cli.php -a finish 2>&1

chown -R 82:82 /var/www/html/data /var/www/html/custom /var/www/html/install/config.php
chmod -R 777 /var/www/html/data /var/www/html/custom

touch "$MARKER"
echo "=== Setup complete ==="

exec supervisord -c /etc/supervisord.conf -n

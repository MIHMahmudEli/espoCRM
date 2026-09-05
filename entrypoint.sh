#!/bin/sh

MARKER="/var/www/html/data/.setup-done"

if [ ! -f "$MARKER" ]; then
    echo "=== EspoCRM auto-setup via CLI installer ==="

    chown -R 82:82 /var/www/html/data /var/www/html/custom
    chmod -R 777 /var/www/html/data /var/www/html/custom

    cd /var/www/html/install

    echo "[1/5] Saving database settings..."
    php cli.php -a saveSettings -d 'host-name=aws-0-ap-northeast-2.pooler.supabase.com:5432&db-name=postgres&db-user-name=postgres.nghztacvdcmizwjoaktf&db-user-password=MqjuNE5jJTDv9%26B&db-platform=Postgresql&user-lang=en_US'

    echo "[2/5] Building database schema..."
    php cli.php -a buildDatabase

    echo "[3/5] Creating admin user (admin / admin12@#)..."
    php cli.php -a createUser -d 'user-name=admin&user-pass=admin12%40%23'

    echo "[4/5] Saving preferences..."
    php cli.php -a savePreferences -d 'language=en_US'

    echo "[5/5] Finalizing installation..."
    php cli.php -a finish

    chown -R 82:82 /var/www/html/data /var/www/html/custom
    chmod -R 777 /var/www/html/data /var/www/html/custom

    touch "$MARKER"
    echo "=== Setup complete ==="
fi

exec supervisord -c /etc/supervisord.conf -n

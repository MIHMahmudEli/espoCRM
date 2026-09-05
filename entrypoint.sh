#!/bin/sh

CONFIG_FILE="/var/www/html/data/config.php"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Running EspoCRM auto-setup..."
    php /var/www/html/auto-setup.php
    chown -R 82:82 /var/www/html/data
    chmod -R 777 /var/www/html/data
    chown -R 82:82 /var/www/html/custom
    chmod -R 777 /var/www/html/custom
    echo "Setup finished."
fi

exec supervisord -c /etc/supervisord.conf -n

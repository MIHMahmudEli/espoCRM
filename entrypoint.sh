#!/bin/sh

CONFIG_FILE="/var/www/html/data/config.php"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Running EspoCRM auto-setup..."
    php /var/www/html/auto-setup.php
    echo "Setup finished."
fi

exec supervisord -c /etc/supervisord.conf -n

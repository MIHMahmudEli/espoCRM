#!/bin/sh

MARKER="/var/www/html/data/.setup-done"

if [ ! -f "$MARKER" ]; then
    echo "=== EspoCRM auto-setup ==="

    chown -R 82:82 /var/www/html/data /var/www/html/custom
    chmod -R 777 /var/www/html/data /var/www/html/custom

    php /var/www/html/auto-setup.php

    SETUP_EXIT=$?

    chown -R 82:82 /var/www/html/data /var/www/html/custom
    chmod -R 777 /var/www/html/data /var/www/html/custom

    if [ $SETUP_EXIT -ne 0 ]; then
        echo "Setup failed with exit code $SETUP_EXIT. Web installer will be used."
    fi
fi

exec supervisord -c /etc/supervisord.conf -n

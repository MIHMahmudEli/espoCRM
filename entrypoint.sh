#!/bin/sh

# Run auto-setup if needed
php /var/www/html/auto-setup.php

# Start services
exec supervisord -c /etc/supervisord.conf -n

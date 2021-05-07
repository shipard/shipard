#!/usr/bin/env bash

/var/www/e10-server/e10/server/etc/services/e10-service-cache.php &
echo $! > /var/run/e10-service-cache.pid

exit 0

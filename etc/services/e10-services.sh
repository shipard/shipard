#!/usr/bin/env bash

/var/www/e10-server/e10/server/etc/services/e10-services.php &
echo $! > /var/run/e10-services.pid

exit 0

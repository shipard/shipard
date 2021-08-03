#!/usr/bin/env bash

/var/www/e10-server/e10pro/hosting/server/tools/e10-certs-generate.php &
echo $! > /var/run/e10-certs-generate.pid

exit 0

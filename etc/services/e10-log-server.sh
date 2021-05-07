#!/usr/bin/env bash

cd /usr/lib/e10/devel/e10-server/utils/logServer
node server.js &
echo $! > /var/run/e10-log-server.pid

exit 0

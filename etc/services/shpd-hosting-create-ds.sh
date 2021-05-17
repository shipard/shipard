#!/bin/bash
source /etc/default/shipard
${SHPD_ROOT_DIR}/tools/shpd-hosting-create-ds.sh &
echo $! > /var/lib/shipard/shpd/shpd-hosting-create-ds.pid

exit 0

#!/usr/bin/env bash
source /etc/default/shipard
${SHPD_ROOT_DIR}/tools/shpd-ds-services.php &
echo $! > /var/lib/shipard/shpd/shpd-ds-services.pid

exit 0

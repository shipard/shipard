#!/usr/bin/env bash
source /etc/default/shipard
${SHPD_ROOT_DIR}/tools/shpd-ds-queue.php &
echo $! > /var/lib/shipard/shpd/shpd-ds-queue.pid

exit 0

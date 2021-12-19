#!/bin/bash
source /etc/default/shipard
${SHPD_ROOT_DIR}/tools/shpd-hosting-upload.php &
echo $! > /var/lib/shipard/shpd/shpd-hosting-upload.pid

exit 0

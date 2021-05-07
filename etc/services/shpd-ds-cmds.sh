#!/bin/bash
source /etc/default/shipard
${SHPD_ROOT_DIR}/tools/shpd-ds-cmds.php &
echo $! > /run/shpd-ds-cmds.pid

exit 0

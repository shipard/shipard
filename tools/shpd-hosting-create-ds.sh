#!/bin/bash

source /etc/default/shipard
while [ true ]
do
	${SHPD_ROOT_DIR}/tools/shpd-hosting-create-ds.php
	sleep 60
done

exit 0

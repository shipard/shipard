#!/bin/sh

cd /var/www/data-sources
/var/www/e10-modules/integrations/ntf/ext-ntf-delivery-service/e10-ext-ntf-delivery-service.php &
echo $! > /var/run/ext-ntf-delivery-service.pid
exit 0

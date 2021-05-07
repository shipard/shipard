#!/usr/bin/env php
<?php


function doIt ()
{
	$logFileName = '/var/lib/e10/tmp/e10-certs-generate-'.time().'.log';
	$cmd = 'cd /root && /opt/dehydrated/dehydrated --cron >'.$logFileName;
	exec ($cmd);
	$cmd = 'cd /var/www/data-sources/50842906798390 && e10-app cli-action --action=e10pro.hosting.server/master-certs-scan';
	exec ($cmd);
}


while (1)
{
	doIt();
	sleep (900);
}


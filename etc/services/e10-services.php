#!/usr/bin/env php
<?php

function doIt ()
{
	$cmd = 'cd /var/www/data-sources && e10 app-walk app-cron --type=services --quiet';
	exec ($cmd);
}


while (1)
{
	doIt();
	sleep (300);
}


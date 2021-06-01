#!/usr/bin/env php
<?php

function doIt ()
{
	$cmd = 'shpd-server app-walk app-cron --type=services --quiet';
	exec ($cmd);
}

while (1)
{
	doIt();
	sleep (300);
}

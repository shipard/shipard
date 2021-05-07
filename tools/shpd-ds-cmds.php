#!/usr/bin/env php
<?php

function doDSCommands ()
{
	forEach (glob ('/var/lib/shipard/dscmd/*.json') as $cmdfn)
	{
		$cmd = 'shpd-server app-dscmd --file="'.$cmdfn.'"';
		passthru ($cmd);
	}
}

while (TRUE)
{
	doDSCommands();

	sleep(30);
}

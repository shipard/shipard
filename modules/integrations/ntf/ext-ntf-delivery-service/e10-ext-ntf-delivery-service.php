#!/usr/bin/env php
<?php

function doOne()
{
	forEach (glob ('*', GLOB_ONLYDIR) as $appDir)
	{
		if (is_link ($appDir))
			continue;
		if (!is_file ($appDir.'/config/config.json'))
			continue;
		if (!is_file ($appDir.'/tmp/integration-ext-ntf-delivery'))
			continue;

		chdir ($appDir);
		exec ('e10-app cli-action --action=integrations.ntf/ext-ntf-delivery');
		chdir ('..');
	}
}

while (1)
{
	$timeStart = time();
	$timeBreak = $timeStart + 60;

	doOne();

	while (time() < $timeBreak)
		sleep (1);
}

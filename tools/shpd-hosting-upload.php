#!/usr/bin/env php
<?php

function uploadFiles ($settings, $files)
{
	$uploadUrl = $settings['dsUrl'].'upload/'.$settings['table'].'/';

	$ch = curl_init();
	foreach ($files as $fileName)
	{
		$bn = basename($fileName);
		if ($bn[0] === '.')
			continue;

		$postData = file_get_contents($fileName);
		//curl_setopt ($ch, CURLOPT_HEADER, 0);
		curl_setopt ($ch, CURLOPT_VERBOSE, 0);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($ch, CURLOPT_URL, $uploadUrl);
		curl_setopt ($ch, CURLOPT_POST, true);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $postData);
		$result = curl_exec ($ch);

		if ($result === 'OK')
			unlink ($fileName);

		//echo " - upload  $fileName : $result \n";
	}

	curl_close ($ch);
}


function waitForFiles ()
{
	$uploadDir = '/var/lib/shipard/upload/';

	while (1)
	{
		forEach (glob ($uploadDir.'*', GLOB_ONLYDIR) as $dir)
		{
			//echo "# scan dir $dir \n";
			$cfgString = file_get_contents($dir.'/.settings');
			if ($cfgString === FALSE)
				continue;
			$cfgData = json_decode($cfgString, TRUE);
			if ($cfgData === FALSE)
				continue;

			//echo ("  ".json_encode($cfgData)."\n");

			$files = glob ($dir.'/*.*');
			if (count($files))
			{
				uploadFiles ($cfgData, $files);
			}
		}

		sleep (30);
	}
}


waitForFiles ();

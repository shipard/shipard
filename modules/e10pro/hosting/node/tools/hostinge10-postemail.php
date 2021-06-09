#!/usr/bin/env php
<?php

if (!defined('__SHPD_ETC_DIR__'))
	define('__SHPD_ETC_DIR__', '/etc/shipard');


function logMsg($lfn, $msg)
{
	//file_put_contents($lfn, $msg."\n", FILE_APPEND);
	echo $msg."\n";
}

function serverCfg ()
{
	$cfgServer = $this->loadCfgFile(__SHPD_ETC_DIR__.'/server.json');
	if (!$cfgServer)
	{
		echo "ERROR: invalid server configuration; check file ".__SHPD_ETC_DIR__.'/server.json'."\n";
		return NULL;
	}

	return $cfgServer;
}

function machineDeviceId ()
{
	$deviceId = file_get_contents('/etc/e10-device-id.cfg');
	return $deviceId;
}

function saveEmail ()
{
	$now = new \DateTime();
	$nowStr = $now->format('Y-m-d_H:i:s');
	$bn = $nowStr.'-'.sha1 (mt_rand(12345, 987654321) . time() . '-' . mt_rand(1111111111, 9999999999)) . '.eml';

	$destFileName = '/var/lib/shipard/email/' . $bn;
	$fileReader = fopen ('php://stdin', 'r');
  $fileWriter = fopen ($destFileName, "w+");

  while (true)
	{
		$buffer = fread ($fileReader, 1024);
		if (strlen ($buffer) == 0)
		{
			fclose ($fileReader);
			fclose ($fileWriter);
			break;
		}
    fwrite ($fileWriter, $buffer);
  }

	return $destFileName;
}


function uploadEmail ($mailFileName, $rcptEmail)
{
	echo "!!$rcptEmail!!\n";
	$lfn = $mailFileName.'.log';
	logMsg($lfn, 'incoming email to `'.$rcptEmail.'`');
	$debug = 0;

	$subAddress = '';

	if ($rcptEmail[0] !== '~')
	{
		$addrParts = explode ('@', $rcptEmail);
		$rcptDomain = $addrParts[1];
		$mainAddrParts = explode ('+', $addrParts[0]);
		$destEmailAddress = $mainAddrParts[0];
		if (isset($mainAddrParts[1]))
			$subAddress = $mainAddrParts[1];

		if ($subAddress === '')
		{
			$mainAddrParts = explode ('--', $addrParts[0]);
			if (isset($mainAddrParts[1]))
			{
				$destEmailAddress = $mainAddrParts[0];
				$subAddress = $mainAddrParts[1];
			}
		}

		// -- detect upload url
		$cfg = cfgServer();
		$hostingServer = $cfg ['hostingDomain'];

		$hostingApiKey = $cfg ['hostingApiKey'];
		$opts = array(
			'http'=>array(
				'method'=>"GET",
				'header'=>
						"e10-api-key: " . $hostingApiKey . "\r\n".
						"e10-device-id: " . machineDeviceId (). "\r\n".
						"Connection: close\r\n"
			)
		);
		$context = stream_context_create($opts);

		$url =  $hostingServer . 'api/call/e10pro.hosting.server.getUploadUrl?address='.$destEmailAddress;
		$resultCode = file_get_contents ($url, false, $context);
		logMsg($lfn, 'detect upload url via `'.$url.'`');
		logMsg($lfn, 'result: `'.$resultCode.'`');

		$resultData = json_decode ($resultCode, true);

		if (!isset ($resultData ['data']['url']))
		{
			logMsg($lfn, 'ERROR: upload url not found');
			return FALSE;
		}

		// -- upload
		$url = $resultData ['data']['url'];
	}
	else
	{
		$testingParts = explode(',', $rcptEmail);
		$url = $testingParts[1];
		echo " -- TEST: !$url! ".json_encode($testingParts)."\n";

		$dstEmailAddress = substr($testingParts[0], 1);
		$addrParts = explode ('@', $dstEmailAddress);
		$rcptDomain = $addrParts[1];
		$mainAddrParts = explode ('+', $addrParts[0]);
		$destEmailAddress = $mainAddrParts[0];
		if (isset($mainAddrParts[1]))
			$subAddress = $mainAddrParts[1];

		if ($subAddress === '')
		{
			$mainAddrParts = explode ('--', $addrParts[0]);
			if (isset($mainAddrParts[1]))
			{
				$destEmailAddress = $mainAddrParts[0];
				$subAddress = $mainAddrParts[1];
			}
		}

		$debug = 1;
	}

	$uploadUrl = $url.'/upload/e10pro.wkf.messages/e10pro.wkf.messages/0/email.eml';
	if ($subAddress !== '')
		$uploadUrl .= '?subAddress='.$subAddress;

	if ($debug)
	{
		echo " * URL: " . $uploadUrl . "\n";
		echo " * email-parts: !$destEmailAddress! + !$subAddress! \n";
	}

	logMsg($lfn, 'upload email via `'.$uploadUrl.'`');

	$fp = fopen ($mailFileName, "r");

  $ch = curl_init();
	curl_setopt ($ch, CURLOPT_HEADER, 0);
  curl_setopt ($ch, CURLOPT_VERBOSE, 0);
  curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt ($ch, CURLOPT_URL, $uploadUrl);
	curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt ($ch, CURLOPT_INFILE, $fp);
	curl_setopt ($ch, CURLOPT_INFILESIZE, filesize($mailFileName));

	curl_setopt ($ch, CURLOPT_UPLOAD, true);
	$result = curl_exec ($ch);
	curl_close ($ch);

	if (is_string($result))
		logMsg($lfn, 'result1: `'.$result.'`');
	else
		logMsg($lfn, 'result2: `'.json_encode($result).'`');

	//unlink ($mailFileName);

	if ($result === FALSE)
	{
		logMsg($lfn, 'ERROR: upload failed');
	}


	return TRUE;
} // uploadEmail

echo "TEST!!!\n";

$emailFileName = saveEmail ();
uploadEmail ($emailFileName, $argv[2]);


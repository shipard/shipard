#!/usr/bin/env php
<?php

function logMsg ($msg)
{
	$now = new \DateTime();
	$lfn = '/var/lib/shipard/tmp/shpd-hosting-create-ds-'.$now->format('Y-m-d').'.log';
	file_put_contents($lfn, $msg."\n", FILE_APPEND);
	//echo $msg."\n";
}

function machineDeviceId ()
{
	$deviceId = file_get_contents('/etc/shipard/device-id.json');
	return $deviceId;
}

function createNewDataSource ()
{
	$cfgServerString = file_get_contents ('/etc/shipard/server.json');
	$cfgServer = json_decode ($cfgServerString, true);
	if (!$cfgServer)
	{
		echo "ERROR: invalid server configuration - file not found or syntax error";
		return false;
	}

	if (!isset($cfgServer['useHosting']))
	{
		echo "ERROR: invalid server configuration - missing `useHosting`";
		return false;
	}

	if (!isset($cfgServer['hostingDomain']))
	{
		echo "ERROR: invalid server configuration - missing `useHosting`";
		return false;
	}

	if (!$cfgServer['hostingDomain'])
		return true;


	$hostingDomain = $cfgServer ['hostingDomain'];
	$serverId = $cfgServer ['serverId'];
	$documentRoot = $cfgServer ['dsRoot'];
	$dbPassword = $cfgServer ['dbPassword'];

	$hostingApiKey = $cfgServer ['hostingApiKey'];
	$opts = array(
		'http' => [
			'timeout' => 10,
			'method' => "GET",
			'header' =>
				"e10-api-key: " . $hostingApiKey . "\r\n".
				"e10-device-id: " . machineDeviceId (). "\r\n".
				"Connection: close\r\n"
		]
	);
	$context = stream_context_create($opts);

	$url = 'https://' .$hostingDomain . '/api/call/e10pro.hosting.server.getNewDataSource?serverId=' . $serverId;
	logMsg('--- get data from ' . $url);
	$resultCode = file_get_contents($url, false, $context);

	$resultData = json_decode($resultCode, true);
	if ($resultData === false)
	{
		logMsg('* ERROR: syntax error in data:');
		logMsg($resultCode);
		return false;
	}

	if ($resultData ['data']['count'] != 1)
	{
		logMsg('--- DONE: no request found');
		return false;
	}

	logMsg('--- START: createNewDataSource ----');
	logMsg(json_encode($resultData,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	logMsg('---');
	$dsid = $resultData ['data']['request']['gid'];
	
	
	$moduleParts = explode('.', $resultData ['data']['installModule']);
	$module = 'install/apps/'.array_pop($moduleParts);
	
	if (str_starts_with ($resultData ['data']['installModule'], 'pkgs.')) // old module?
		$resultData ['data']['installModule'] = substr($resultData ['data']['installModule'], 5); // TODO: remove in new hosting

	logMsg('* chdir: ' . $documentRoot);
	chdir($documentRoot);
	$cmd = "shpd-server app-create --name=$dsid --module=$module";

	logMsg('* exec: ' . $cmd);
	passthru($cmd);

	if (!is_dir($documentRoot . '/' . $dsid))
		return false;

	logMsg('* chdir: ' . $documentRoot . '/' . $dsid);
	chdir($documentRoot . '/' . $dsid);
	file_put_contents('config/createApp.json', json_encode($resultData ['data'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

	$dsInfo = ['dsid' => strval ($dsid)];
	file_put_contents('config/dataSourceInfo.json', json_encode($dsInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	$cmd = 'shpd-server app-init --password=' . $dbPassword;
	passthru($cmd);

	$cmd = 'shpd-server app-getdsinfo';
	passthru($cmd);

	$cmd = 'shpd-server app-fullupgrade';
	passthru($cmd);

	$url = 'https://' . $hostingDomain . "/api/call/e10pro.hosting.server.confirmNewDataSource?serverId={$serverId}&dsid={$dsid}";
	$resultCode2 = file_get_contents($url, false, $context);
	$resultData2 = json_decode($resultCode2, true);

	$cmd = 'shpd-server app-publish';
	passthru($cmd);

	logMsg('--- DONE: createNewDataSource ----');

	return true;
}


while (true)
{
	if (!createNewDataSource ())
		break;
}

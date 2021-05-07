#!/usr/bin/env php
<?php


function logMsg ($msg)
{
	$now = new \DateTime();
	$lfn = '/var/lib/e10/tmp/e10-ds-cmds-'.$now->format('Y-m-d').'.log';
	file_put_contents($lfn, $msg."\n", FILE_APPEND);
}

function machineDeviceId ()
{
	$deviceId = file_get_contents('/etc/e10-device-id.cfg');
	return $deviceId;
}

function createNewDataSource ()
{
	$cfgString = file_get_contents ('/etc/e10----hosting.cfg');
	$cfgAll = json_decode ($cfgString, true);

	$cntAdded = 0;
	foreach ($cfgAll as $cfg)
	{
		if (!isset($cfg['createDataSources']) || !$cfg['createDataSources'])
			continue;

		$hostingServer = $cfg ['hostingServerUrl'];
		$hostingServerId = $cfg ['thisServerId'];
		$documentRoot = $cfg ['documentRoot'];
		$dbPassword = $cfg ['dbPassword'];

		$hostingApiKey = $cfg ['hostingApiKey'];
		$opts = array(
			'http' => array(
				'timeout' => 1,
				'method' => "GET",
				'header' =>
					"e10-api-key: " . $hostingApiKey . "\r\n".
					"e10-device-id: " . machineDeviceId (). "\r\n".
					"Connection: close\r\n"
			)
		);
		$context = stream_context_create($opts);

		$url = $hostingServer . 'api/call/e10pro.hosting.server.getNewDataSource?serverId=' . $hostingServerId;

		logMsg('--- get data from ' . $url);

		$resultCode = file_get_contents($url, false, $context);


		$resultData = json_decode($resultCode, true);
		if ($resultData === FALSE)
		{
			logMsg('* ERROR: syntax error in data:');
			logMsg($resultCode);
			continue;
		}

		if ($resultData ['data']['count'] != 1)
		{
			logMsg('--- DONE: no request found');
			continue;
		}

		logMsg('--- START: createNewDataSource ----');


		$dsid = $resultData ['data']['request']['gid'];
		$module = str_replace('.', '/', $resultData ['data']['installModule']);


		chdir($documentRoot);
		$cmd = "e10 app-create --name=$dsid --module=$module";

		logMsg('* exec: ' . $cmd);
		passthru($cmd);

		logMsg('* chdir: ' . $documentRoot . '/' . $dsid);
		chdir($documentRoot . '/' . $dsid);
		file_put_contents('config/createApp.json', json_encode($resultData ['data'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

		$dsInfo = ['dsid' => strval ($dsid), 'hosting' => strval($cfg ['hostingGid'])];
		file_put_contents('config/dataSourceInfo.json', json_encode($dsInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
		$cmd = 'e10 app-init --password=' . $dbPassword;
		passthru($cmd);

		$cmd = 'e10 app-getdsinfo';
		passthru($cmd);

		$cmd = 'e10 app-fullupgrade';
		passthru($cmd);

		$url = $hostingServer . "api/call/e10pro.hosting.server.confirmNewDataSource?serverId=$hostingServerId&dsid=$dsid";
		$resultCode2 = file_get_contents($url, false, $context);
		$resultData2 = json_decode($resultCode2, true);

		$cmd = 'e10 app-publish';
		passthru($cmd);

		logMsg('--- DONE: createNewDataSource ----');

		$cntAdded++;
	}

	return $cntAdded;
} // createNewSource


function doDSCommands ()
{
	forEach (glob ('/var/lib/e10/dscmd/*.json') as $cmdfn)
	{
		$cmd = 'e10 app-dscmd --file='.$cmdfn;
		passthru ($cmd);
	}
}


while (TRUE)
{
	doDSCommands();

	if (!createNewDataSource ())
		break;
}





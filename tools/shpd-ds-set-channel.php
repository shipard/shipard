#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());

$cfgServerString = @file_get_contents ('config/_server_channelInfo.json');
if (!$cfgServerString)
{
	echo "### ERROR: file `config/_server_channelInfo.json` not found.\n";
	exit(100);
}

$cfgServer = json_decode ($cfgServerString, true);
if (!$cfgServer)
{
	echo "### ERROR: file `config/_server_channelInfo.json` is not valid.\n";
	exit(101);
}

define('__SHPD_ROOT_DIR__', $cfgServer['serverInfo']['channelPath']);

require_once __SHPD_ROOT_DIR__ . '/src/boot.php';



function setChannel ($channel)
{
	$channelConfigFileName = __APP_DIR__ . '/config/_server_channelInfo.json';

	$activeChannelCfg = \Shipard\Utils\Utils::loadCfgFile ($channelConfigFileName);
	if ($activeChannelCfg === FALSE)
	{
		echo '###ERROR: file `'.$activeChannelCfg.'` is invalid'."\n";
		return;
	}

	$activeChannel = $activeChannelCfg['serverInfo']['channelId'];
	if ($channel === $activeChannel)
	{
		echo 'This is active channel'."\n";
		return;
	}

	$serverChannelCfg = $cfgServer['channels'][$channel] ?? NULL;
	if (!$serverChannelCfg)
	{
		echo 'Channel `'.$channel.'` is invalid'."\n";
		return;
	}

	$cfg = [
		'serverInfo' => ['channelId' => $channel, 'channelPath' => $serverChannelCfg['path']],
	];
	file_put_contents($channelConfigFileName, \Shipard\Utils\Json::lint($cfg));


	$cmd = 'shpd-ds app-upgrade';
	passthru($cmd);
	$cmd = 'shpd-ds app-fullupgrade';
	passthru($cmd);

	passthru('systemctl reload php8.3-fpm');
}


$arguments = \Shipard\Utils\Utils::parseArgs($argv);
$channel = $arguments[0] ?? '';

setChannel($channel);

#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());

$cfgServerString = file_get_contents ('config/_server_channelInfo.json');
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

$app = new \Shipard\CLI\Server\ShpdDSApp ();
$app->run ($argv);

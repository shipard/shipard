#!/usr/bin/env php
<?php

if (!defined ('__SHPD_ROOT_DIR__'))
{
	$parts = explode('/', __DIR__);
	array_pop($parts);
	define('__SHPD_ROOT_DIR__', implode('/', $parts).'/');
}

if (!defined('__SHPD_ETC_DIR__'))
	define('__SHPD_ETC_DIR__', '/etc/shipard');

if (!defined ('__SHPD_VAR_DIR__'))
	define ('__SHPD_VAR_DIR__', '/var/lib/shipard/');



class Installation
{
	public function isSuperuser ()
	{
		return (0 === posix_getuid());
	}

	public function wwwGroup ()
	{
		return 'shpd';
	}

	protected function generatePassword ($len = 9)
	{
		$r = '';
		for($i = 0; $i < $len; $i++)
			$r .= chr (rand (0, 25) + ord('a'));
		return $r;
	}

	protected function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return FALSE;
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return FALSE;
			return $cfg;
		}
		return FALSE;
	}

	protected function checkEtc()
	{
		if (!is_dir(__SHPD_ETC_DIR__))
		{
			mkdir(__SHPD_ETC_DIR__, 0750, TRUE);
			chown (__SHPD_ETC_DIR__, $this->wwwGroup());
			chgrp (__SHPD_ETC_DIR__, $this->wwwGroup());
		}
	}

	protected function checkServerConfig()
	{
		if (is_readable(__SHPD_ETC_DIR__.'/server.json'))
		{
			echo "Server config exist\n";
			return;
		}

		$defaultChannelId = 'devel';
		$dsRoot = '/var/lib/shipard/data-sources/';
		$dbPassword = $this->generatePassword();

		$serverConfig = [
			'serverDomain' => 'localhost.shpd.dev',
			'serverId' => '',
			'serverGID' => '',

			'channels' => [
				$defaultChannelId => ['path' => __SHPD_ROOT_DIR__],
			],
			'defaultChannel' => $defaultChannelId,
			'dsRoot' => $dsRoot,
			'dbUser' => 'root',
			'dbPassword' => $dbPassword,

			'develMode' => 0,

			'useHosting' => 0,
			'hostingDomain' => '',
			'hostingApiKey' => '',

			'userFirstName' => '',
			'userLastName' => '',
			'userEmail' => '',
		];

		file_put_contents (__SHPD_ETC_DIR__.'/server.json', json_encode($serverConfig, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
	}

	protected function checkExtLibs()
	{
		if (!str_starts_with(__SHPD_ROOT_DIR__, '/usr/lib/'))
			return;

		$composerLockFileName = __SHPD_ROOT_DIR__ . '/extlibs/composer.lock';
		if (is_file($composerLockFileName))
			return;

		$cmd = 'cd ' . __SHPD_ROOT_DIR__ . '/extlibs && composer -n update --no-plugins --no-scripts';
		passthru($cmd);
	}

	public function checkDirs()
	{
		if (!is_file('/usr/lib/shipard'))
			symlink(__SHPD_ROOT_DIR__, '/usr/lib/shipard');

		if (!is_file('/bin/shpd-server'))
			symlink( __SHPD_ROOT_DIR__.'/tools/shpd-server.php', '/bin/shpd-server');

		if (!is_file('/bin/shpd-app'))
			symlink(__SHPD_ROOT_DIR__.'/tools/shpd-app.php', '/bin/shpd-app');

		if (!is_file('/etc/default/shipard'))
			copy(__SHPD_ROOT_DIR__.'/etc/default/shipard', '/etc/default/shipard');
	}

	public function run()
	{
		if (!$this->isSuperuser())
		{
			echo("ERROR: Need to be root\n");
			return;
		}

		//echo "__SHPD_ROOT_DIR__ : " . __SHPD_ROOT_DIR__ . "\n";
		//echo "__SHPD_ETC_DIR__  : " . __SHPD_ETC_DIR__  . "\n";
		//echo "__SHPD_VAR_DIR__  : " . __SHPD_VAR_DIR__  . "\n";

		$this->checkDirs();
		$this->checkEtc();
		$this->checkServerConfig();
		$this->checkExtLibs();
	}
}

$i = new Installation();
$i->run();

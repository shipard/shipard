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

	protected function checkEtc()
	{
		if (!is_dir(__SHPD_ETC_DIR__))
			mkdir(__SHPD_ETC_DIR__, 0750, TRUE);
	}

	protected function initDevelConfig()
	{
		if (is_readable($_SERVER['HOME'].'/.shipad/devel.json'))
		{
			echo "Development config exist\n";
			return;
		}

		if (!is_dir($_SERVER['HOME'] . '/.shipad'))
			mkdir($_SERVER['HOME'] . '/.shipad');

		$develConfig = [
			'firstName' => '',
			'lastName' => '',
			'email' => '',
		];

		file_put_contents ($_SERVER['HOME'].'/.shipad/devel.json', json_encode($develConfig, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
	}

	protected function checkExtLibs()
	{
		$composerLockFileName = __SHPD_ROOT_DIR__ . '/extlibs/composer.lock';
		if (is_file($composerLockFileName))
			return;

		$cmd = 'cd ' . __SHPD_ROOT_DIR__ . '/extlibs && composer -n install --no-plugins --no-scripts';
		passthru($cmd);
	}

	public function run()
	{
		if ($this->isSuperuser())
		{
			echo("ERROR: Need to be normal user, not root\n");
			return;
		}

		echo "__SHPD_ROOT_DIR__ : " . __SHPD_ROOT_DIR__ . "\n";
		echo "__SHPD_ETC_DIR__  : " . __SHPD_ETC_DIR__  . "\n";
		echo "__SHPD_VAR_DIR__  : " . __SHPD_VAR_DIR__  . "\n";

		$this->checkExtLibs();
		$this->initDevelConfig();
	}
}

$i = new Installation();
$i->run();

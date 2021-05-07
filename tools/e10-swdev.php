#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());
require_once __DIR__ . '/e10-cli.php';

use \E10\CLI\Application, \E10\utils, \E10\DataModel, \e10\json;


/**
 * Class E10SwDevApp
 */
class E10SwDevApp extends Application
{
	var $quiet = FALSE;
	var $devServerCfg = NULL;
	var $curl = NULL;

	public function msg ($msg)
	{
		if (!$this->quiet)
			echo '* ' . $msg . "\r\n";
	}

	public function runApiCall ($url, $apiKey, $data)
	{
		if (!$this->curl)
		{
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
				'Connection: Keep-Alive',
				'Keep-Alive: 300',
				'e10-api-key: ' . $apiKey,
				'e10-device-id: ' . utils::machineDeviceId()
			]);
			curl_setopt ($this->curl, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt ($this->curl, CURLOPT_POST, true);
		}

		curl_setopt ($this->curl, CURLOPT_URL, $url);
		curl_setopt ($this->curl, CURLOPT_POSTFIELDS, json::encode($data));
		$resultCode = curl_exec ($this->curl);
		$resultData = json_decode ($resultCode, TRUE);

		return $resultData;
	}

	public function appWalk ()
	{
		$paramsArray = $_SERVER ['argv'];
		unset ($paramsArray [1]);
		$cmd = implode (' ', $paramsArray);

		forEach (glob ('*', GLOB_ONLYDIR) as $appDir)
		{
			if (is_link ($appDir))
				continue;
			if (is_file($appDir.'/.disable-upgrade'))
				continue;
			if (is_file ($appDir.'/config/config.json'))
			{
				$this->msg ("---- $appDir");
				chdir ($appDir);
				passthru ($cmd);
				chdir ('..');
			}
		}
	}

	function dmUpload()
	{
		$e = new \swdev\dm\SwDevCfgManager();
		$e->app = $this;
		$e->devServerCfg = $this->devServerCfg;

		$e->upload();
	}

	function enumsUpload()
	{
		$e = new \swdev\dm\libs\EnumsManagerUpload($this);
		$e->devServerCfg = $this->devServerCfg;

		$e->run();
	}

	function trDownload()
	{
		$e = new \swdev\translation\libs\TranslationDownloaderCLI($this);
		$e->getTables();
		$e->getDicts();
		$e->getEnums();
	}

	function worldUpload()
	{
		$e = new \swdev\world\SwDevWorldCreator($this);
		$e->devServerCfg = $this->devServerCfg;

		$e->upload();
	}


	function loadDevServerCfg()
	{
		$fn = (isset ($_SERVER['HOME']) ? $_SERVER['HOME'] : '/root').'/.e10/devel.json';
		if (!is_file($fn))
		{
			return $this->err ("DevServerCfg not found; file `$fn` not exist...");
		}

		$this->devServerCfg = utils::loadCfgFile($fn);
		if (!$this->devServerCfg)
			return $this->err ("Invalid DevServerCfg; file `$fn` is not valid...");

		if (!isset($this->devServerCfg['devServerApiKey']))
			return $this->err ("Invalid DevServerCfg; value `devServerApiKey` not found...");
		if (!isset($this->devServerCfg['devServerUrl']))
			return $this->err ("Invalid DevServerCfg; value `devServerUrl` not found...");

		return TRUE;
	}

	function createPackages()
	{
		$e = new \swdev\world\StdDataCreator($this);
		$e->run();
	}

	public function run ()
	{
		$this->quiet = $this->arg ('quiet');

		switch ($this->command ())
		{
			case	'create-packages':							return $this->createPackages();
		}

		if (!$this->loadDevServerCfg())
			return FALSE;

		switch ($this->command ())
		{
			case	'dm-upload':										return $this->dmUpload();
			case	'enums-upload':								return $this->enumsUpload();
			case	'tr-download':									return $this->trDownload();
			case	'world-upload':								return $this->worldUpload();
		}
		echo ("unknown or nothing param...\r\n");
	}

	public function superuser ()
	{
		return (0 == posix_getuid());
	}
}

$myApp = new E10SwDevApp ($argv);
$myApp->run ();


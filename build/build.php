#!/usr/bin/env php
<?php

if (!defined ('__SHPD_ROOT_DIR__'))
{
	$parts = explode('/', __DIR__);
	array_pop($parts);
	define('__SHPD_ROOT_DIR__', '/'.implode('/', $parts).'/');
}

define ("__APP_DIR__", getcwd());



require_once __SHPD_ROOT_DIR__ . '/src/boot.php';

use Shipard\Utils\Utils;


use \E10\CLI\Application;
use \e10\json;

function parseArgs($argv)
{
	// http://pwfisher.com/nucleus/index.php?itemid=45
		array_shift ($argv);
		$out = array();
		foreach ($argv as $arg){
				if (substr($arg,0,2) == '--'){
						$eqPos = strpos($arg,'=');
						if ($eqPos === false){
								$key = substr($arg,2);
								$out[$key] = isset($out[$key]) ? $out[$key] : true;
						} else {
								$key = substr($arg,2,$eqPos-2);
								$out[$key] = substr($arg,$eqPos+1);
						}
				} else if (substr($arg,0,1) == '-'){
						if (substr($arg,2,1) == '='){
								$key = substr($arg,1,1);
								$out[$key] = substr($arg,3);
						} else {
								$chars = str_split(substr($arg,1));
								foreach ($chars as $char){
										$key = $char;
										$out[$key] = isset($out[$key]) ? $out[$key] : true;
								}
						}
				} else {
						$out[] = $arg;
				}
		}
		return $out;
}


class ShpdBuildApp
{
	var $arguments;

	var $allTemplates = [];
	var $allLooks = [];

	public function __construct ()
	{
	}

	public function arg ($name)
	{
		if (isset ($this->arguments [$name]))
			return $this->arguments [$name];

		return FALSE;
	}

	public function command ($idx = 0)
	{
		if (isset ($this->arguments [$idx]))
			return $this->arguments [$idx];

		return "";
	}

	public function err ($msg)
	{
		echo $msg . "\r\n";
		return FALSE;
	}

	public function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return $this->err ("read file failed: $fileName");
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return $this->err ("parsing file failed: $fileName");
			return $cfg;
		}
		return $this->err ("file not found: $fileName");
	}

	public function msg ($msg)
	{
		echo '* ' . $msg . "\r\n";
	}

	public function build ()
	{
		// git branch --show-current

		$live = $this->arg('live');

		$pkg = utils::loadCfgFile('version.json');
		if (!$pkg)
			return $this->err("File version.json not found.");

		$channel = ($live) ? 'live' : 'devel';
		$commit = shell_exec("git log --pretty=format:'%h' -n 1");

		$packageCoreName = 'shipard-'.$channel;

		$versionId = $pkg['version'].'-'.$commit;
		if ($live)
			$versionId .= '_'.base_convert(time(), 10, 36);
		$baseFileName = $packageCoreName.'-'.$versionId;
		$pkgFileName = 'build/packages/'.$baseFileName.'.tgz';

		if ($live)
			$cmd = "tar --exclude=.git --exclude=extlibs/vendor --exclude=extlibs/composer.lock --exclude=build/packages --transform \"s/^/\\/usr\\/lib\\/{$packageCoreName}-{$versionId}\\//\" -czf $pkgFileName .";
		else
			$cmd = "git archive --format=tar.gz -o $pkgFileName --prefix=/usr/lib/{$packageCoreName}-$versionId/ devel";
		passthru($cmd);

		$fileCheckSum = sha1_file($pkgFileName);
		echo "* $baseFileName: version $versionId, checksum $fileCheckSum \n";

		$versionInfo = ['channel' => $channel, 'version' => $versionId, 'fileName' => $baseFileName.'.tgz', 'installVer' => 1, 'checkSum' => $fileCheckSum];
		$verFileName = "../{$packageCoreName}.info";
		file_put_contents($verFileName, json::lint($versionInfo));

		$pkgUrl = "https://download.shipard.org/shipard/{$baseFileName}.tgz";
		$installFileNameDebian = "build/packages/{$packageCoreName}-install-deb.cmd";
		$installCmd = "#!/bin/sh\n";
		$installCmd .= "echo \"* Download package {$pkgUrl}\"\n";
		$installCmd .= "wget {$pkgUrl}\n";
		$installCmd .= "echo \"Unpacking {$baseFileName}.tgz\"\n";
		$installCmd .= "tar -xzf {$baseFileName}.tgz -C /\n";
		$installCmd .= "[ -d \"/usr/lib/{$packageCoreName}\" ] && rm -rf /usr/lib/{$packageCoreName}\n";
		$installCmd .= "mv /usr/lib/{$packageCoreName}-{$versionId} /usr/lib/{$packageCoreName}\n";
		//$installCmd .= "echo \"Install debian packages\"\n";
		//$installCmd .= "[ ! -h /usr/lib/shipard ] && ln -s /usr/lib/{$packageCoreName} /usr/lib/shipard\n";
		//$installCmd .= "[ ! -h /bin/shpd-server ] && ln -s /usr/lib/shipard/tools/shpd-server.php /bin/shpd-server\n";
		//$installCmd .= "[ ! -h /bin/shpd-app ] && ln -s /usr/lib/shipard/tools/shpd-app.php /bin/shpd-app\n";
		//$installCmd .= "[ ! -h /etc/default/shipard ] && cp /usr/lib/shipard/etc/default/shipard /etc/default\n";
		$installCmd .= "rm {$baseFileName}.tgz\n";
		$installCmd .= "echo \"DONE\"\n";
		$installCmd .= "\nexit 0\n";
		file_put_contents($installFileNameDebian, $installCmd);

		$cmd = "scp $verFileName $pkgFileName $installFileNameDebian {$_SERVER['USER']}@shipardPackages:/var/www/webs/download.shipard.org/shipard/";
		echo "* Copying to server...";
		passthru($cmd);
		echo " done.\n";
	}

	public function run ($argv)
	{
		$this->arguments = parseArgs($argv);

		if (count ($this->arguments) == 0)
			return $this->help ();

		switch ($this->command ())
		{
			case	"build":		return $this->build ();
		}

		echo ("unknown command...\n");

		return FALSE;
	}

	function help ()
	{
		echo
			"usage: build command arguments\r\n\r\n" .
			"commands:\r\n" .
			"   build: build [--live|--<BRANCH>]\r\n" .
			"\r\n";

		return true;
	}
}


$app = new ShpdBuildApp ();
$app->run ($argv);

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



class InstallationDb
{
	public function isSuperuser ()
	{
		return (0 === posix_getuid());
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
	
	public function checkDb()
	{
		$cfgServer = $this->loadCfgFile(__SHPD_ETC_DIR__.'/server.json');
		if (!$cfgServer || !isset($cfgServer['dbPassword']))
		{
			echo "ERROR: invalid server configuration; check file ".__SHPD_ETC_DIR__.'/server.json'."\n";
			return;
		}

		// -- mysql_secure_installation
		$cmd = "mysqladmin -u root password {$cfgServer['dbPassword']}";
		passthru($cmd);

		//$sqlCmd .= "DELETE FROM mysql.user WHERE User=''; ";
		
		//$sqlCmd .= "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1'); ";
		// DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');

		$sqlCmd = "DROP DATABASE IF EXISTS test; ";
		$sqlCmd .= "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%'; ";
		$sqlCmd .= "FLUSH PRIVILEGES;";
		
		$cmd = 'mysql -u root -e "'.$sqlCmd.'"';
		passthru($cmd);

		// -- enable root on localhost
		//$sqlCmd  = "USE mysql; ";
		//$sqlCmd .= "UPDATE user SET plugin='' WHERE user='root'; ";
		//$sqlCmd .= "FLUSH PRIVILEGES;";
		//$cmd = "mysql -u root -p{$cfgServer['dbPassword']} -e \"".$sqlCmd.'"';
		//passthru($cmd);
	}
	
	public function run()
	{
		if (!$this->isSuperuser())
		{
			echo("ERROR: Need to be root\n");
			return;
		}

		$this->checkDb();
	}
}

$i = new InstallationDb();
$i->run();

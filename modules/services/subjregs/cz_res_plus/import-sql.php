#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());
require_once __APP_DIR__ . '/e10-modules/e10/server/php/e10-cli.php';

use \E10\CLI\Application, \E10\utils, \E10\DataModel, \e10\json;


/**
 * Class ImportSqlApp
 */
class ImportSqlApp extends Application
{
	function dropTables ()
	{
		$this->db()->query ('DROP TABLE [services_subjregs_cz_res_plus_res]');
		$this->db()->query ('DROP TABLE [services_subjregs_cz_res_plus_rzpProvoz]');
		$this->db()->query ('DROP TABLE [services_subjregs_cz_res_plus_rzpSubj]');

		passthru('e10 app-upgrade');
	}

	function import ()
	{
		$login = self::cfgItem ('db.login');
		$password = self::cfgItem ('db.password');
		$database = self::cfgItem ('db.database');
		$fileName = __APP_DIR__.'/res.sql';

		$cmd = "mysql -u {$login} -p{$password} {$database} < ".$fileName;
		passthru($cmd);
	}

	public function run()
	{
		$this->dropTables();
		$this->import();
	}
}


$app = new ImportSqlApp($argv);
$app->run();


<?php

namespace e10\world;

use e10\utils;


/**
 * Class ModuleServices
 * @package e10\world
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$this->checkFirstInstall();
	}

	public function checkFirstInstall ()
	{
		$exist = $this->app->db()->query ('SELECT ndx FROM [e10_world_countries] WHERE [ndx] = %i', 1)->fetch();
		if ($exist)
			return;

		$cfg = utils::loadCfgFile(__APP_DIR__.'/config/config.json');
		$dataFileName = __SHPD_MODULES_DIR__.'install/data/world/world-mysql.sql';

		$cmd = "cat $dataFileName|MYSQL_PWD={$cfg['db']['password']} mysql --default-character-set=utf8mb4 -u {$cfg['db']['login']} {$cfg['db']['database']}";
		exec($cmd);
	}

	protected function cliImportAdmUnits ()
	{
		$importer = new \e10\world\libs\ImportAdmUnits($this->app);
		$importer->country = 60;
		$importer->run();
		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'import-adm-units': return $this->cliImportAdmUnits();
		}

		parent::onCliAction($actionId);
	}
}

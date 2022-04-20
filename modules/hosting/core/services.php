<?php

namespace hosting\core;


/**
 * Class ModuleServices
 * @package hosting\core
 */
class ModuleServices extends \Shipard\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$this->checkSymlinks();

		$s [] = ['end' => '2022-04-30', 'sql' => "UPDATE hosting_core_dataSources SET dsType = 1 WHERE dsType > 2"];
		$this->doSqlScripts ($s);
	}

	public function checkSymlinks ()
	{
		if (!is_link (__APP_DIR__.'/users.php'))
			symlink (__SHPD_MODULES_DIR__.'hosting/core/users.php', 'users.php');
	}

	public function onCronEver ()
	{
		$this->cliUpdateServersUpdownIO();

		return TRUE;
	}

	protected function cliUpdateServersUpdownIO()
	{
		$e = new \hosting\core\libs\ServersUpdateUpdownIO($this->app());
		$e->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'update-servers-updown-io': return $this->cliUpdateServersUpdownIO();
		}

		return parent::onCliAction($actionId);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}
}

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
	}

	public function checkSymlinks ()
	{
		if (!is_link (__APP_DIR__.'/users.php'))
			symlink (__SHPD_MODULES_DIR__.'hosting/core/users.php', 'users.php');
	}

	public function onCronMorning ()
	{
		// -- get servers statistics
		//$tableServerStats = $this->app->table('hosting.core.serversStats');
		//$tableServerStats->downloadStats();
	}

	public function onCronEver ()
	{
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'morning': $this->onCronMorning(); break;
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}
}

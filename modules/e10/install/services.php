<?php

namespace e10\install;


/**
 * Class ModuleServices
 * @package e10\install
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$ie = new \e10\install\libs\InstallEngine($this->app);
		$ie->checkUpgradeToNewSystem();

		$ie->checkDataPackages();
	}
}

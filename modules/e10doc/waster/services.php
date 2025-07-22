<?php

namespace e10doc\waster;
use \Shipard\Utils\Utils;


/**
 * Class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function resetWasteOps ()
	{
		// TODO: remove
		$year = intval($this->app->arg('year'));
		if (!$year)
		{
			echo "ERROR: param `--year=` not found\n";
			return;
		}

		$wre = new \e10doc\waster\libs\WasteOpsGenerator($this->app);
		$wre->year = $year;
		$wre->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'reset-waste-ops': return $this->resetWasteOps();
		}

		return parent::onCliAction($actionId);
	}
}

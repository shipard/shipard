<?php

namespace e10pro\reports\waste_cz;
use \Shipard\Utils\Utils;


/**
 * Class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function resetWasteReturn ()
	{
		$year = intval($this->app->arg('year'));
		if (!$year)
		{
			echo "ERROR: param `--year=` not found\n";
			return;
		}

		$wre = new \e10pro\reports\waste_cz\libs\WasteReturnEngine($this->app);
		$wre->year = $year;

		$wre->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'reset-waste-return': return $this->resetWasteReturn();
		}

		return parent::onCliAction($actionId);
	}
}

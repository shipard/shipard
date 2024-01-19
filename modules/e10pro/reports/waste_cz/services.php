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


	public function repairWasteReturn ()
	{
		$dateBeginStr = $this->app->arg('dateBegin');
		if (!$dateBeginStr)
		{
			echo "ERROR: param `--dateBegin=YYYY-MM-DD` not found\n";
			return;
		}
		$dateBegin = Utils::createDateTime($dateBeginStr);
		if ($dateBegin === NULL)
		{
			echo "ERROR: param `--dateBegin=YYYY-MM-DD` has bad format\n";
			return;
		}

		$dateEndStr = $this->app->arg('dateEnd');
		if (!$dateEndStr)
		{
			echo "ERROR: param `--dateEnd=YYYY-MM-DD` not found\n";
			return;
		}
		$dateEnd = Utils::createDateTime($dateEndStr);
		if ($dateEnd === NULL)
		{
			echo "ERROR: param `--dateEnd=YYYY-MM-DD` has bad format\n";
			return;
		}

		$wce = new \e10pro\reports\waste_cz\libs\WasteCheckEngine($this->app);
		$wce->repair($dateBegin, $dateEnd);

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'reset-waste-return': return $this->resetWasteReturn();
			case 'repair-waste-return': return $this->repairWasteReturn();
		}

		return parent::onCliAction($actionId);
	}
}

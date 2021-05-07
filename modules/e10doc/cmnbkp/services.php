<?php

namespace e10doc\cmnbkp;
use e10\utils, e10doc\core\e10utils;
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


/**
 * Class ModuleServices
 * @package e10doc\cmnbkp
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function openAccPeriodReset()
	{
		$dateArg = $this->app->arg('date');
		if (!$dateArg)
			return $this->app->err('arg `--date` is missing');

		$date = utils::createDateTime($dateArg);
		if (utils::dateIsBlank($date))
			return $this->app->err('Invalid `--date` param value');

		$fiscalYear = e10utils::todayFiscalYear($this->app, $date);
		if (!$fiscalYear)
			return $this->app->err('Fiscal period for `--date` param not found');

		$reset = new \e10doc\cmnbkp\libs\OpenAccPeriodReset($this->app());
		$reset->fiscalYear = $fiscalYear;
		$reset->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'open-acc-period-reset': return $this->openAccPeriodReset();
		}

		return parent::onCliAction($actionId);
	}
}

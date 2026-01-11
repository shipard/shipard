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

	protected function createMuniReports()
	{
		$wasteReturn = intval($this->app->arg('wasteReturn'));
		if (!$wasteReturn)
		{
			echo "ERROR: param `--wasteReturn=' not found...\n";
			return;
		}

		$engine = new \e10doc\waster\libs\MuniReportsCreator($this->app);
		$engine->createAll($wasteReturn);
	}

	protected function sendMuniReports()
	{
		$wasteReturn = intval($this->app->arg('wasteReturn'));
		if (!$wasteReturn)
		{
			echo "ERROR: param `--wasteReturn=' not found...\n";
			return;
		}

		$forceGovBoxId = $this->app->arg('forceGovBoxId');

		$engine = new \e10doc\waster\libs\SendMuniReportsEngine($this->app);
		$engine->wasteReturnNdx = $wasteReturn;
		if ($forceGovBoxId !== FALSE)
			$engine->forceGovBoxId = $forceGovBoxId;

		$engine->sendAll();
	}

	protected function createCompaniesReportsIn()
	{
		$wasteReturn = intval($this->app->arg('wasteReturn'));
		if (!$wasteReturn)
		{
			echo "ERROR: param `--wasteReturn=' not found...\n";
			return;
		}

		$engine = new \e10doc\waster\libs\CompaniesReportsCreator($this->app);
		$engine->createAll($wasteReturn);
	}


	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'reset-waste-ops': return $this->resetWasteOps();
			case 'create-muni-reports': return $this->createMuniReports();
			case 'send-muni-reports': return $this->sendMuniReports();
			case 'create-companies-reports-in': return $this->createCompaniesReportsIn();
		}

		return parent::onCliAction($actionId);
	}
}

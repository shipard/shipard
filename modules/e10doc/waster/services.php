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

	protected function sendCompaniesReports()
	{
		$wasteReturn = intval($this->app->arg('wasteReturn'));
		if (!$wasteReturn)
		{
			echo "ERROR: param `--wasteReturn=' not found...\n";
			return;
		}

		$forceEmailTo = $this->app->arg('forceEmailTo');

		$engine = new \e10doc\waster\libs\SendCompaniesReportsEngine($this->app);
		$engine->wasteReturnNdx = $wasteReturn;
		if ($forceEmailTo !== FALSE)
			$engine->forceEmailTo = $forceEmailTo;

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

	protected function createCompaniesReportsOut()
	{
		$wasteReturn = intval($this->app->arg('wasteReturn'));
		if (!$wasteReturn)
		{
			echo "ERROR: param `--wasteReturn=' not found...\n";
			return;
		}

		$engine = new \e10doc\waster\libs\CompaniesReportsCreator($this->app);
		$engine->wasteDir = 1;
		$engine->createAll($wasteReturn);
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'reset-waste-ops': return $this->resetWasteOps();
			case 'create-muni-reports': return $this->createMuniReports();
			case 'send-muni-reports': return $this->sendMuniReports();
			case 'send-companies-reports': return $this->sendCompaniesReports();
			case 'create-companies-reports-in': return $this->createCompaniesReportsIn();
			case 'create-companies-reports-out': return $this->createCompaniesReportsOut();
		}

		return parent::onCliAction($actionId);
	}
}

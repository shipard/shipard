<?php

namespace E10Pro\Purchase;

use E10\utils;


/**
 * Class ModuleServices
 * @package E10Pro\Purchase
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function generateBankOrders()
	{
		if (!$this->app->production())
			return;

		$generateBankOrders = intval($this->app->cfgItem ('options.e10doc-buy.purchBankOrders', 0));
		$doIt = FALSE;
		$now = new \DateTime ();
		$hour = intval($now->format('H'));

		$title = '';
		if ($generateBankOrders === 1 && $hour === 17) {
			$doIt = TRUE; // once daily
			$title = 'Úhrady výkupů ze dne '.utils::datef ($now, '%d');
		}
		if ($generateBankOrders === 2 && ($hour === 12 || $hour === 17)) {
			$doIt = TRUE; // twice daily
			$title = 'Úhrady '.(($hour === 12) ? 'dopoledních' : 'odpoledních').' výkupů ze dne '.utils::datef ($now, '%d');
		}
		if ($generateBankOrders === 3 && ($hour > 7 && $hour < 21)) {
			$doIt = TRUE; // hourly - working hours only
			$title = 'Úhrady výkupů do '.$now->format('H.i').' ze dne '.utils::datef ($now, '%d');
		}

		if (!$doIt)
			return;

		$params = ['docType' => 'purchase', 'title' => $title];
		$engine = new \lib\docs\BankOrderGenerator($this->app);
		$engine->setParams($params);
		$engine->run();

		if (!$engine->bankOrderNdx)
			return;

		$uploadBankOrders = intval($this->app->cfgItem ('options.e10doc-buy.purchUploadBankOrders', 0));
		if ($uploadBankOrders)
			\lib\ebanking\upload\UploadBankOrder::upload($this->app, $engine->bankOrderNdx);
	}

	protected function sendWasteReportPersons()
	{
		$action = new \e10pro\reports\waste_cz\ReportWasteOnePersonAction($this->app);
		$action->testRun = 1;

		$yearParam = intval($this->app->arg('year'));
		if (!$yearParam)
		{
			echo "ERROR: param `--year=' not found...\n";
			return;
		}

		$wasteCodeKindParam = intval($this->app->arg('wasteCodeKind'));
		if (!$wasteCodeKindParam)
		{
			echo "ERROR: param `--wasteCodeKind=' not found...\n";
			return;
		}

		$runParam = intval($this->app->arg('run'));
		if ($runParam)
			$action->testRun = 0;

		$maxCountParam = intval($this->app->arg('maxCount'));
		if ($maxCountParam)
			$action->maxCount = $maxCountParam;

		$debugParam = intval($this->app->arg('debug'));
		if ($debugParam)
			$action->debug = 1;

		$action->runFromCli($yearParam, $wasteCodeKindParam);
	}

	public function onCronHourly ()
	{
		$this->generateBankOrders();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'hourly': $this->onCronHourly(); break;
		}
		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'send-waste-report-persons': return $this->sendWasteReportPersons();
		}

		parent::onCliAction($actionId);
	}
}

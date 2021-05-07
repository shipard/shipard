<?php

namespace services\money\libs;

use e10\utils, e10\Utility;


/**
 * Class ExchangeRateListsDownloader
 * @package services\money\libs
 */
class ExchangeRateListsDownloader extends Utility
{
	var $dateFrom = NULL;
	var $dateTo = NULL;
	var $exrLists;
	var $interactive = 0;

	function checkDates()
	{
		if (!$this->dateFrom)
			$this->dateFrom = utils::today();
		if (!$this->dateTo)
			$this->dateTo = utils::today();
	}

	public function setDateFrom($dateFrom)
	{
		$this->dateFrom = utils::createDateTime($dateFrom);
	}

	public function setDateTo($dateTo)
	{
		$this->dateTo = utils::createDateTime($dateTo);
	}

	function downloadOneDay ($date)
	{
		foreach ($this->exrLists as $exrListNdx => $exrList)
		{
			if (!$exrListNdx)
				continue;

			$classId = $exrList['classId'];
			$o = $this->app()->createObject($classId);
			$o->init();
			$o->downloadDay($date);

			unset ($o);
		}
	}

	public function run()
	{
		$this->exrLists = $this->app()->cfgItem('services.money.exchangeRatesLists', []);
		$this->checkDates();

		$date = $this->dateFrom;
		$cnt = 0;
		while ($date <= $this->dateTo)
		{
			if ($this->interactive)
				echo "--- ".$date->format('Y-m-d')." ---\n";
			$this->downloadOneDay($date);
			$date->add (new \DateInterval('P1D'));
			$cnt++;

			if ($cnt % 10 === 0)
				sleep(5);
		}
	}
}


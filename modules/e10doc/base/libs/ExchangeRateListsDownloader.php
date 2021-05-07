<?php

namespace e10doc\base\libs;
use e10\utils, e10\Utility;


/**
 * Class ExchangeRateListsDownloader
 * @package e10doc\base\libs
 */
class ExchangeRateListsDownloader extends Utility
{
	var $dateFrom = NULL;
	var $dateTo = NULL;
	var $exrLists;
	var $interactive = 0;

	/** @var \e10doc\base\TableExchangeRatesLists */
	var $tableRatesLists;
	/** @var \e10doc\base\TableExchangeRatesValues */
	var $tableRatesValues;

	function checkDates()
	{
		if (!$this->dateFrom)
			$this->dateFrom = utils::today();
		if (!$this->dateTo)
			$this->dateTo = utils::today();

		$this->tableRatesLists = $this->app()->table('e10doc.base.exchangeRatesLists');
		$this->tableRatesValues = $this->app()->table('e10doc.base.exchangeRatesValues');
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

			$monthDay = intval($date->format ('d'));
			if (isset($exrList['download']['monthDay']) && $exrList['download']['monthDay'] !== $monthDay)
				continue;

			$weekDay = intval($date->format ('N')) - 1; // Monday is 0
			if (isset($exrList['download']['workingDays']) && $weekDay > 4)
				continue;

			$today = utils::today();
			if ($date == $today)
			{
				$now = new \DateTime();
				$nowTime = intval($now->format ('Hi'));

				if (isset($exrList['download']['afterTime']) && $nowTime < $exrList['download']['afterTime'])
					continue;
			}

			// -- exist?
			$exist = $this->db()->query('SELECT * FROM [e10doc_base_exchangeRatesLists] WHERE [validFrom] = %d', $date,
				' AND [listType] = %i', $exrListNdx)->fetch();
			if ($exist)
			{
				continue;
			}

			// -- download
			$url = 'https://services.shipard.com/feed/exchange-rates-list/'.$date->format('Y-m-d').'/'.$exrListNdx;
			$exchangeRatesListStr = file_get_contents($url);
			if (!$exchangeRatesListStr)
			{
				continue;
			}

			$exchangeRatesList = json_decode($exchangeRatesListStr, true);
			if (!$exchangeRatesList || !isset($exchangeRatesList['object']['data']))
			{
				continue;
			}

			$this->save($exchangeRatesList['object']['data']);
		}
	}

	function save($list)
	{
		$list['rec']['docState'] = 1000;
		$list['rec']['docStateMain'] = 0;

		$listNdx = $this->tableRatesLists->dbInsertRec($list['rec']);

		foreach ($list['values'] as &$r)
		{
			$r['list'] = $listNdx;
			$this->tableRatesValues->dbInsertRec($r);
		}

		$this->db()->query ('UPDATE [e10doc_base_exchangeRatesLists] SET docState = %i', 4000, ', docStateMain = %i', 2, ' WHERE ndx = %i', $listNdx);
		$this->tableRatesLists->docsLog($listNdx);
	}

	public function run()
	{
		$this->exrLists = $this->app()->cfgItem('e10doc.base.exchangeRatesLists', []);
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
		}
	}
}


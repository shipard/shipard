<?php

namespace services\money\libs\download;

use e10\utils, \e10\world, \services\money\libs\download\DownloadCore;


/**
 * Class Download_CZ_CNB
 * @package services\money\libs\download
 */
class Download_CZ_CNB extends DownloadCore
{
	public function init()
	{
		if ($this->listTypeId === 0)
			$this->listTypeId = 1;

		parent::init();
	}

	function parseData()
	{
		$this->listHead = [];
		$this->listRows = [];

		$this->listHead['listData'] = $this->txtData;

		$rows = preg_split("/\\r\\n|\\r|\\n/", $this->txtData);
		$hr1 = array_shift($rows);
		$hr1Parts = explode (' ', $hr1);
		$listNumber = intval(substr($hr1Parts[1], 1));

		$listDate = \DateTime::createFromFormat("d.m.Y", $hr1Parts[0]);
		$this->listNumber = intval($listDate->format('y'))*1000 + $listNumber;

		if ($this->listExist())
		{
			$this->saveList = 0;
			return;
		}

		$hr2 = array_shift($rows);

		foreach ($rows as $r)
		{
			if ($r === '')
				continue;

			$cols = explode ('|', $r);

			$currencyNdx = world::currencyNdx($this->app(), $cols[3]);
			$exchangeRate = floatval(str_replace(',', '.', $cols[4]));

			$listRow = [
				'list' => 0, 'currency' => $currencyNdx, 'cntUnits' => intval($cols[2]),
				'exchangeRate' => $exchangeRate,
			];

			$this->listRows[] = $listRow;
		}
	}

	public function downloadDay($date)
	{
		$weekDay = intval($date->format ('N')) - 1; // Monday is 0
		if ($weekDay > 4)
		{
			return 1;
		}

		$today = utils::today();
		if ($date == $today)
		{
			$now = new \DateTime();
			$nowTime = intval($now->format ('Hi'));
			if ($nowTime < 1430) // new list is available after 14:30
				return 1;

			$this->doneFileName = __APP_DIR__.'/tmp/exr-download-'.$this->listTypeId.'-'.$date->format('Ymd').'.done';
			if (is_readable($this->doneFileName))
				return 1;
		}

		$this->dateValidFrom = $date;
		$this->dateValidTo = $date;

		$url = 'http://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/denni_kurz.txt?date='.$date->format('d.m.Y');
		$txtData = file_get_contents($url);
		if ($txtData === FALSE)
			return 2;
		$this->setTxtData($txtData);

		$this->parseData();
		if ($this->saveList)
			$this->save();

		return 0;
	}
}

<?php

namespace services\money\libs\download;

use e10\utils, \e10\world, \services\money\libs\download\DownloadCore;


/**
 * Class Download_CZ_Customs
 * @package services\money\libs\download
 */
class Download_CZ_Customs extends DownloadCore
{
	public function init()
	{
		$this->listTypeId = 3;

		parent::init();
	}

	function parseData($main = TRUE)
	{
		if ($main)
		{
			$this->listHead = [];
			$this->listRows = [];
		}

		if ($main)
			$this->listHead['listData'] = $this->txtData;
		else
			$this->listHead['listData'] .= "\n\n".$this->txtData;

		$rows = preg_split("/\\r\\n|\\r|\\n/", $this->txtData);
		$hr1 = array_shift($rows);

		foreach ($rows as $r)
		{
			if ($r === '')
				continue;

			$cols = explode (';', $r);
			$dateCols = explode (' ', $cols[3]);
			$listDate = \DateTime::createFromFormat("d.m.Y", $dateCols[0]);
			if ($listDate->format('Ym') !== $this->dateValidFrom->format('Ym'))
				continue;

			$currencyNdx = world::currencyNdx($this->app(), $cols[0]);
			$exchangeRate = floatval(str_replace(',', '.', $cols[2]));

			$listRow = [
				'list' => 0, 'currency' => $currencyNdx, 'cntUnits' => intval($cols[1]),
				'exchangeRate' => $exchangeRate,
			];

			$this->listRows[] = $listRow;
		}
	}

	public function downloadDay($date)
	{
		$today = utils::today();
		if ($today->format('Ym') !== $date->format('Ym'))
			return 0; // only this month is available

		$this->listNumber = intval($date->format('y'))*1000 + intval($date->format('m'));

		if ($this->listExist())
		{
			return 1;
		}

		$this->dateValidFrom = $date;
		$this->dateValidTo = new \DateTime($date->format('Y-m-t'));

		$url = 'http://www.celnisprava.cz/cz/aplikace/Stranky/kurzy.aspx?er=1&type=TXT';
		$txtData = file_get_contents($url);
		if ($txtData === FALSE)
			return 2;
		$this->setTxtData($txtData);
		$this->parseData();

		$url = 'http://www.celnisprava.cz/cz/aplikace/Stranky/kurzy.aspx?er=0&type=TXT';
		$txtData = file_get_contents($url);
		if ($txtData !== FALSE)
		{
			$this->setTxtData($txtData);
			$this->parseData(FALSE);
		}

		if (!count($this->listRows))
			$this->saveList = 0;

		if ($this->saveList)
			$this->save();

		return 0;
	}
}

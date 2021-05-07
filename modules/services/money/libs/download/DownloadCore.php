<?php

namespace services\money\libs\download;

use e10\utils, e10\world, e10\Utility;


/**
 * Class DownloadCore
 * @package services\money\libs\download
 */
class DownloadCore extends Utility
{
	var $listTypeId = 0;
	var $listTypeCfg = NULL;
	var $listHead = [];
	var $listRows = [];

	var $dateValidFrom = NULL;
	var $dateValidTo = NULL;
	var $listNumber = 0;
	var $txtData = NULL;
	var $saveList = 1;

	var $doneFileName = NULL;

	/** @var \services\money\TableExchangeRatesLists */
	var $tableRatesLists;
	/** @var \services\money\TableExchangeRatesValues */
	var $tableRatesValues;

	public function init()
	{
		$this->tableRatesLists = $this->app()->table('services.money.exchangeRatesLists');
		$this->tableRatesValues = $this->app()->table('services.money.exchangeRatesValues');
		$this->listTypeCfg = $this->app()->cfgItem ('services.money.exchangeRatesLists.'.$this->listTypeId, NULL);
	}

	public function downloadDay($date)
	{
	}

	function save()
	{
		$this->listHead['listType'] = $this->listTypeId;

		if ($this->dateValidFrom)
			$this->listHead['validFrom'] = $this->dateValidFrom;
		if ($this->dateValidTo)
			$this->listHead['validTo'] = $this->dateValidTo;
		if ($this->listNumber)
			$this->listHead['listNumber'] = $this->listNumber;

		$this->listHead['country'] = world::countryNdx($this->app(), $this->listTypeCfg['country']);
		$this->listHead['currency'] = world::currencyNdx($this->app(), $this->listTypeCfg['currency']);
		$this->listHead['periodType'] = $this->listTypeCfg['periodType'];
		$this->listHead['rateType'] = $this->listTypeCfg['rateType'];

		$this->listHead['docState'] = 1000;
		$this->listHead['docStateMain'] = 0;

		$listNdx = $this->tableRatesLists->dbInsertRec($this->listHead);

		foreach ($this->listRows as &$r)
		{
			$r['list'] = $listNdx;
			$this->tableRatesValues->dbInsertRec($r);
		}

		$this->db()->query ('UPDATE [services_money_exchangeRatesLists] SET docState = %i', 4000, ', docStateMain = %i', 2, ' WHERE ndx = %i', $listNdx);
		$this->tableRatesLists->docsLog($listNdx);

		if ($this->doneFileName !== NULL)
			touch ($this->doneFileName);
	}

	function setTxtData($txtData)
	{
		$this->txtData = $txtData;
	}

	function listExist()
	{
		$exist = $this->db()->query ('SELECT * FROM [services_money_exchangeRatesLists] WHERE [listType] = %i', $this->listTypeId,
			' AND [listNumber] = %i', $this->listNumber)->fetch();

		if ($exist)
			return $exist['ndx'];

		return 0;
	}
}


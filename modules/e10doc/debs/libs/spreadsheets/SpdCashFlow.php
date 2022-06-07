<?php

namespace e10doc\debs\libs\spreadsheets;
use \e10doc\debs\libs\AccDataConnector;

use e10\utils;


/**
 * Class SpdCashFlow
 * @package pkgs\accounting\debs
 */
class SpdCashFlow extends \lib\spreadsheets\Spreadsheet
{
	var $balanceSheetSpreadsheetId = '';
	var $statementSpreadsheetId = '';

	public function init ()
	{
		$this->dataSet = $this->dataConnector();
		$this->dataSet->reverseSign = FALSE;
		$this->dataSet->setAccKinds ([
			$this->dataSet->ackExpenses, $this->dataSet->ackRevenues,
			$this->dataSet->ackAssets, $this->dataSet->ackLiabilities, $this->dataSet->ackAssetsLiabilities
		]);
		$this->dataSet->setSpreadsheet($this);
		$this->dataSet->init();
	}

	function dataConnector()
	{
		return new AccDataConnector($this->app);
	}

	function loadOtherSpreadsheets()
	{
//  fiscalPeriod								- aktuální období
//	initStateFiscalPeriod				- období poč. stavu aktuálního období
//	prevFiscalPeriod						- minulé období
//	initStatePrevFiscalPeriod		- období poč. stavu minulého období

		// -- balanceSheet
		$spd = new SpdBalanceSheet ($this->app);
		$spd->spreadsheetId = $this->balanceSheetSpreadsheetId;
		forEach ($this->params as $paramKey => $paramValue)
		{
			if ($paramKey === 'prevFiscalPeriod' || $paramKey === 'initStateFiscalPeriod' || $paramKey === 'initStatePrevFiscalPeriod')
				continue;
			$spd->setParam($paramKey, $paramValue);
		}
		$spd->setParam('prevFiscalPeriod', $this->params['initStateFiscalPeriod']);
		$spd->run ();
		$this->otherSpreadsheets['balanceSheet'] = $spd;

		// -- statement
		$spd = new SpdStatement($this->app);
		$spd->spreadsheetId = $this->statementSpreadsheetId;
		forEach ($this->params as $paramKey => $paramValue)
		{
			if ($paramKey === 'prevFiscalPeriod' || $paramKey === 'initStateFiscalPeriod' || $paramKey === 'initStatePrevFiscalPeriod')
				continue;
			$spd->setParam($paramKey, $paramValue);
		}
		$spd->setParam('prevFiscalPeriod', $this->params['initStateFiscalPeriod']);
		$spd->run ();
		$this->otherSpreadsheets['statement'] = $spd;

		if (!isset($this->params['prevFiscalPeriod']))
			return;

		// -- cashFlow - prev fiscal period
		$spd = new SpdCashFlow ($this->app);
		$spd->spreadsheetId = $this->spreadsheetId;
		if ($this->balanceSheetSpreadsheetId !== '')
			$spd->balanceSheetSpreadsheetId = $this->balanceSheetSpreadsheetId;
		if ($this->statementSpreadsheetId !== '')
			$spd->statementSpreadsheetId = $this->statementSpreadsheetId;

		forEach ($this->params as $paramKey => $paramValue)
		{
			if ($paramKey === 'fiscalPeriod' || $paramKey === 'prevFiscalPeriod'||
					$paramKey === 'initStateFiscalPeriod' || $paramKey === 'initStatePrevFiscalPeriod')
				continue;
			$spd->setParam($paramKey, $paramValue);
		}
		$spd->setParam('fiscalPeriod', $this->params['prevFiscalPeriod']);
		$spd->setParam('initStateFiscalPeriod', $this->params['initStatePrevFiscalPeriod']);

		$spd->run ();
		$this->otherSpreadsheets['prev'] = $spd;
	}

}

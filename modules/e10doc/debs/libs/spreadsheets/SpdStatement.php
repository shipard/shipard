<?php

namespace e10doc\debs\libs\spreadsheets;

use \e10\utils;


/**
 * Class SpdStatement
 * @package pkgs\accounting\debs
 */
class SpdStatement extends SpdAccCore
{
	public function init ()
	{
		$this->dataSet = $this->dataConnector();
		$this->dataSet->setAccKinds ([$this->dataSet->ackExpenses, $this->dataSet->ackRevenues]);
		$this->dataSet->setSpreadsheet($this);
		$this->dataSet->init();
	}

	function testResults()
	{
		if ($this->fullSpreadsheetId !== '')
		{
			$this->testNotes = $this->otherSpreadsheets['full']->testNotes;
			return;
		}

		foreach ($this->dataSet->allData as $accId => $accDef)
		{
			if ($accDef['endState'] == 0.0)
				continue;
			if (strlen ($accId) < 4)
				continue;

			$ac1 = substr($accId, 0, 1);
			$ac2 = substr($accId, 0, 2);
			$ac3 = substr($accId, 0, 3);

			if (isset($this->usedAccounts[$ac1]))
				continue;
			if (isset($this->usedAccounts[$ac2]))
				continue;
			if (isset($this->usedAccounts[$ac3]))
				continue;

			if (isset($this->usedAccounts[$accId]))
				continue;

			$this->testNotes['acc_missing_'.$accId] .= 'Účet '.$accId.' má zůstatek, ale není součástí výkazu ('.utils::nf($accDef['endState'], 2).') ';
		}

		foreach ($this->usedAccounts as $accId => $accountCnt)
		{
			if ($accountCnt > 1)
				$this->testNotes['acc_more_'.$accId] = 'Účet '.$accId.' je ve výkazu použit vícekrát.';
		}
	}

	function loadOtherSpreadsheets()
	{
		if ($this->fullSpreadsheetId !== '')
		{
			$spd = new SpdStatement($this->app);
			$spd->spreadsheetId = $this->fullSpreadsheetId;
			forEach ($this->params as $paramKey => $paramValue)
			{
				$spd->setParam($paramKey, $paramValue);
			}
			$spd->run ();
			$this->otherSpreadsheets['full'] = $spd;
		}

		if (!isset($this->params['prevFiscalPeriod']))
			return;

		// -- prev fiscal period
		$spd = new SpdStatement($this->app);
		$spd->spreadsheetId = $this->spreadsheetId;
		forEach ($this->params as $paramKey => $paramValue)
		{
			if ($paramKey === 'fiscalPeriod' || $paramKey === 'prevFiscalPeriod')
				continue;
			$spd->setParam($paramKey, $paramValue);
		}
		$spd->setParam('fiscalPeriod', $this->params['prevFiscalPeriod']);

		$spd->run ();
		$this->otherSpreadsheets['prev'] = $spd;
	}
}

<?php

namespace e10doc\debs\libs\spreadsheets;
use \e10doc\debs\libs\spreadsheets\SpdCashFlow;
use \e10doc\debs\libs\spreadsheets\SpdStatement;
use \e10doc\debs\libs\spreadsheets\SpdBalanceSheet;
use e10\utils;


/**
 * Class SpdBalanceSheet
 */
class SpdBalanceSheet extends SpdAccCore
{
	var $statementSpreadsheetId = '';

	public function init ()
	{
		$this->dataSet = $this->dataConnector();
		$this->dataSet->reverseSign = FALSE;
		$this->dataSet->setAccKinds ([$this->dataSet->ackAssets, $this->dataSet->ackLiabilities, $this->dataSet->ackAssetsLiabilities]);
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

		foreach ($this->dataSet->allData as $accIdSpd => $accDef)
		{
			if ($accDef['endState'] == 0.0)
				continue;

			$accId = $accIdSpd;
			if ($accId[0] === 'a' || $accId[0] === 'p')
				$accId = substr ($accIdSpd, 1);

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

			$this->testNotes['acc_missing_'.$accId] = 'Účet '.$accId.' má zůstatek, ale není součástí výkazu ('.utils::nf($accDef['endState'], 2).') ';
		}

		foreach ($this->usedAccounts as $accId => $accountCnt)
		{
			if ($accountCnt > 1)
				$this->testNotes['acc_more_'.$accId] = 'Účet '.$accId.' je ve výkazu použit vícekrát.';
		}

		$cellsAccounts = [];
		if (isset($this->tableAccounts['AKTIVA']))
		{
			foreach ($this->tableAccounts['AKTIVA'] as $accIdX => $accCnt)
			{
				$accId = strval($accIdX);
				if (!is_string($accId))
				{
					error_log("####!!!" . json_encode($accId));
					continue;
				}
	
				if ($accId[0] === 'a' || $accId[0] === 'p')
					continue;
				$al = strlen($accId);
				foreach ($this->dataSet->usedAccountsKinds as $usedAccId => $usedAccKind)
				{
					if (substr($usedAccId, 0, $al) != $accId)
						continue;

					if ($this->dataSet->usedAccountsKinds[$usedAccId] != $this->dataSet->ackAssets)
					{
						$this->testNotes['acc_badkind_assets_' . $usedAccId] = 'Účet ' . $usedAccId . ' v Aktivech má špatnou povahu.';
					}

					$cellsAccounts[] = $usedAccId;
				}
			}
		}
		if (isset($this->tableAccounts['PASIVA']))
		{
			foreach ($this->tableAccounts['PASIVA'] as $accIdX => $accCnt)
			{
				$accId = strval($accIdX);
				if (!is_string($accId))
				{
					error_log("####!!!" . json_encode($accId));
					continue;
				}
				if ($accId[0] === 'a' || $accId[0] === 'p')
					continue;
				$al = strlen($accId);
				foreach ($this->dataSet->usedAccountsKinds as $usedAccId => $usedAccKind)
				{
					if (substr($usedAccId, 0, $al) != $accId)
						continue;

					$cellsAccounts[] = $usedAccId;
					if ($this->dataSet->usedAccountsKinds[$usedAccId] != $this->dataSet->ackLiabilities)
					{
						$this->testNotes['acc_badkind_liabilities_' . $usedAccId] = 'Účet ' . $usedAccId . ' v Pasivech má špatnou povahu.';
					}
				}
			}
		}
	}

	function loadOtherSpreadsheets()
	{
		if ($this->fullSpreadsheetId !== '')
		{
			$spd = new SpdBalanceSheet ($this->app);
			$spd->spreadsheetId = $this->fullSpreadsheetId;
			if ($this->statementSpreadsheetId !== '')
				$spd->statementSpreadsheetId = $this->statementSpreadsheetId;
			forEach ($this->params as $paramKey => $paramValue)
			{
				$spd->setParam($paramKey, $paramValue);
			}
			$spd->run ();
			$this->otherSpreadsheets['full'] = $spd;
		}

		if ($this->statementSpreadsheetId !== '')
		{
			$spd = new SpdStatement($this->app);
			$spd->spreadsheetId = $this->statementSpreadsheetId;
			forEach ($this->params as $paramKey => $paramValue)
				$spd->setParam($paramKey, $paramValue);
			$spd->run ();
			$this->otherSpreadsheets['statement'] = $spd;
		}

		if (!isset($this->params['prevFiscalPeriod']))
			return;

		// -- balanceSheet - prev fiscal period
		$spd = new SpdBalanceSheet ($this->app);
		$spd->spreadsheetId = $this->spreadsheetId;
		if ($this->statementSpreadsheetId !== '')
			$spd->statementSpreadsheetId = $this->statementSpreadsheetId;
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

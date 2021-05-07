<?php

namespace e10doc\debs\libs;


/**
 * Class AccDataConnector
 * @package pkgs\accounting\debs
 */
class AccDataConnector extends \lib\spreadsheets\SpreadsheetDataConnector
{
	var $fiscalPeriodRec;
	public $allData;
	public $allTotals;
	public $kindTotals;
	public $accNames;
	public $accKinds = array ();
	public $centre = FALSE;
	public $reverseSign = TRUE;
	public $resultFormat = 0;

	var $usedAccountsKinds = [];

	var $ackAssets 								= 0; // Aktiva
	var $ackLiabilities 					= 1; // Pasiva
	var $ackExpenses							= 2; // Náklady
	var $ackRevenues							= 3; // Výnosy
	var $ackOpenPeriod						= 4; // Otevření období
	var $ackClosePeriod						= 9; // Uzavření období
	var $ackAssetsLiabilities 		= 5; // Aktivně psasivní
	var $ackSubBalance						= 6; // Podrozvaha
	var $ackIntraCompanyExpenses 	= 7; // Vnitropodnikové náklady
	var $ackIntraCompanyRevenues 	= 8; // Vnitropodnikové výnosy

	protected function checkMoney ($money)
	{
		switch($this->resultFormat)
		{
			case	'1000': return round ($money / 1000, 0);
		}
		return $money;
	}

	public function init()
	{
		// -- account names
		$qac = "SELECT id, shortName FROM e10doc_debs_accounts WHERE docStateMain < 3";
		$accounts = $this->app->db()->query($qac);
		$this->accNames = $accounts->fetchPairs ('id', 'shortName');

		$data = [];
		$fiscalPeriod = $this->param ('fiscalPeriod');

		// -- fiscal period
		$this->fiscalPeriodRec = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE ndx = %i", $fiscalPeriod)->fetch ();

		// -- init states
		$accInitStates = $this->rowsInitStates();
		forEach ($accInitStates as $acc)
		{
			$accountKind = $acc['accountKind'];
			$accountId = $acc['accountId'];

			$this->usedAccountsKinds[$accountId] = $accountKind;

			$data[$accountId] = $acc;
			$data[$accountId]['initState'] = $acc['initStateDr'] - $acc['initStateCr'];

			if ($accountKind === $this->ackAssetsLiabilities)
			{ // aktivně/pasivní účty získávají povahu podle zůstatku
				if ($data[$accountId]['initState'] < 0)
					$data[$accountId]['accountKind'] = $this->ackLiabilities;
				else
					$data[$accountId]['accountKind'] = $this->ackAssets;

				$this->usedAccountsKinds[$accountId] = $data[$accountId]['accountKind'];
			}
		}

		// -- month summary
		$accSumM = $this->rowsMonthSummary();
		forEach ($accSumM as $acc)
		{
			$accountId = $acc['accountId'];
			if (isset ($data[$accountId]))
			{
				$data[$accountId]['sumMCr'] = $acc['sumMCr'];
				$data[$accountId]['sumMDr'] = $acc['sumMDr'];
			}
			else
				$data[$accountId] = $acc;
		}

		// -- year summary
		$accSumY = $this->rowsYearSummary();
		forEach ($accSumY as $acc)
		{
			$accountId = $acc['accountId'];
			$accountKind = $acc['accountKind'];
			$this->usedAccountsKinds[$accountId] = $accountKind;

			if (isset ($data[$accountId]))
			{
				$data[$accountId]['sumYCr'] = $acc['sumYCr'];
				$data[$accountId]['sumYDr'] = $acc['sumYDr'];
			}
			else
			{
				$data[$accountId] = $acc;
			}

			if ($accountKind === $this->ackAssetsLiabilities)
			{ // aktivně/pasivní účet
				if (!isset($data[$accountId]['initState']))
					$data[$accountId]['initState'] = 0.0;

				$endBalance = $data[$accountId]['initState'] + $acc['sumYDr'] - $acc['sumYCr'];
				if ($endBalance < 0)
					$data[$accountId]['accountKind'] = $this->ackLiabilities; // pasiva
				else
					$data[$accountId]['accountKind'] = $this->ackAssets; // aktiva

				$this->usedAccountsKinds[$accountId] = $data[$accountId]['accountKind'];
			}
		}

		// totals and end states
		$totals = [];
		forEach ($data as &$acc)
		{
			$accountId = $acc['accountId'];
			$accountKind = $acc['accountKind'];

			$sign = 1;
			if ($this->reverseSign && ($accountKind === $this->ackLiabilities || $accountKind === $this->ackExpenses || $accountKind === $this->ackRevenues))
				$sign = -1; // pasiva, náklady a výnosy jdou mínusem

			$monthState = 0.0;
			$endState = 0.0;
			if (isset ($acc['initState']))
			{
				$endState += $acc['initState'];
				$acc['initState'] = $sign * $this->checkMoney ($acc['initState']);
			}

			if (isset ($acc['sumMDr'])) $monthState += $acc['sumMDr'];
			if (isset ($acc['sumMCr'])) $monthState -= $acc['sumMCr'];
			if (isset ($acc['sumYDr'])) $endState += $acc['sumYDr'];
			if (isset ($acc['sumYCr'])) $endState -= $acc['sumYCr'];

			$acc['monthState'] = $sign * $this->checkMoney ($monthState);
			$acc['endState'] = $sign * $this->checkMoney ($endState);

			$acc['title'] = isset($this->accNames[$accountId]) ? $this->accNames[$accountId] : '';

			$sumIds = array ('ALL', substr ($acc['accountId'], 0, 1), substr ($acc['accountId'], 0, 2), substr ($acc['accountId'], 0, 3));
			forEach ($sumIds as $sumId)
			{
				if (!isset ($totals[$sumId]))
					$totals[$sumId] = [
						'accountKind' => $acc['accountKind'], 'accountId' => $sumId, 'initState' => 0.0, 'monthState' => 0.0, 'endState' => 0.0,
						'sumMCr' => 0.0, 'sumMDr' => 0.0, 'sumYCr' => 0.0, 'sumYDr' => 0.0,
						'title' => isset($this->accNames[$sumId])?$this->accNames[$sumId]:'', 'cntRows' => 0,
						'_options' => array ('class' => 'subtotal')
					];

				if (isset ($acc['initState'])) $totals[$sumId]['initState'] += $acc['initState'];
				if (isset ($acc['monthState'])) $totals[$sumId]['monthState'] += $acc['monthState'];
				if (isset ($acc['endState'])) $totals[$sumId]['endState'] += $acc['endState'];

				if (isset ($acc['sumMCr'])) $totals[$sumId]['sumMCr'] += $this->checkMoney ($acc['sumMCr']);
				if (isset ($acc['sumMDr'])) $totals[$sumId]['sumMDr'] += $this->checkMoney ($acc['sumMDr']);

				if (isset ($acc['sumYCr'])) $totals[$sumId]['sumYCr'] += $this->checkMoney ($acc['sumYCr']);
				if (isset ($acc['sumYDr'])) $totals[$sumId]['sumYDr'] += $this->checkMoney ($acc['sumYDr']);

				$totals[$sumId]['cntRows'] += 1;
			}
		}

		// totals by account kind
		$kindTotals = [];
		forEach ($data as &$acc)
		{
			$accountId = $acc['accountId'];
			$accountKind = '_'.$acc['accountKind'];

			$sumIds = array ('ALL', substr ($accountId, 0, 1), substr ($accountId, 0, 2), substr ($accountId, 0, 3));
			forEach ($sumIds as $sumId)
			{
				if (!isset ($kindTotals[$accountKind][$sumId]))
					$kindTotals[$accountKind][$sumId] = [
						'accountKind' => $acc['accountKind'], 'accountId' => $sumId, 'initState' => 0.0, 'monthState' => 0.0,'endState' => 0.0,
						'sumMCr' => 0.0, 'sumMDr' => 0.0, 'sumYCr' => 0.0, 'sumYDr' => 0.0,
						'title' => isset($this->accNames[$sumId])?$this->accNames[$sumId]:'', 'cntRows' => 0,
						'_options' => array ('class' => 'subtotal')
					];

				if (isset ($acc['initState'])) $kindTotals[$accountKind][$sumId]['initState'] += $acc['initState'];
				if (isset ($acc['monthState'])) $kindTotals[$accountKind][$sumId]['monthState'] += $acc['monthState'];
				if (isset ($acc['endState'])) $kindTotals[$accountKind][$sumId]['endState'] += $acc['endState'];

				if (isset ($acc['sumMCr'])) $kindTotals[$accountKind][$sumId]['sumMCr'] += $this->checkMoney ($acc['sumMCr']);
				if (isset ($acc['sumMDr'])) $kindTotals[$accountKind][$sumId]['sumMDr'] += $this->checkMoney ($acc['sumMDr']);

				if (isset ($acc['sumYCr'])) $kindTotals[$accountKind][$sumId]['sumYCr'] += $this->checkMoney ($acc['sumYCr']);
				if (isset ($acc['sumYDr'])) $kindTotals[$accountKind][$sumId]['sumYDr'] += $this->checkMoney ($acc['sumYDr']);

				$kindTotals[$accountKind][$sumId]['cntRows'] += 1;
			}
		}

		forEach ($data as &$acc)
		{
			if (isset ($acc['sumMCr']))
				$acc['sumMCr'] = $this->checkMoney ($acc['sumMCr']);
			if (isset ($acc['sumMDr']))
				$acc['sumMDr'] = $this->checkMoney ($acc['sumMDr']);

			if (isset ($acc['sumYCr']))
				$acc['sumYCr'] = $this->checkMoney ($acc['sumYCr']);
			if (isset ($acc['sumYDr']))
				$acc['sumYDr'] = $this->checkMoney ($acc['sumYDr']);
		}

		$this->allData = $data;
		$this->allTotals = $totals;
		$this->kindTotals = $kindTotals;
	} // init


	function rowsInitStates()
	{
		$q[] = 'SELECT accounts.accountKind as accountKind, journal.accountId, SUM(journal.moneyDr) as initStateDr, SUM(journal.moneyCr) as initStateCr FROM e10doc_debs_journal as journal ';
		array_push ($q, 'LEFT JOIN e10doc_debs_accounts as accounts ON (journal.accountId = accounts.id AND accounts.docStateMain < 3)');
		array_push ($q, ' WHERE fiscalType = 1 AND fiscalYear = %i', $this->fiscalPeriodRec['fiscalYear']);

		if (count ($this->accKinds) !== 0)
			array_push ($q, ' AND accounts.accountKind IN %in', $this->accKinds);

		if ($this->centre !== FALSE)
			array_push ($q, '  AND centre = %i', $this->centre);

		array_push ($q, ' GROUP BY accountId');

		$data = [];
		$rows = $this->app->db()->query($q);
		forEach ($rows as $acc)
		{
			$data[] = $acc->toArray();
		}
		return $data;
	}

	function rowsMonthSummary()
	{
		$fiscalPeriod = $this->param ('fiscalPeriod');

		$q[] = 'SELECT accounts.accountKind as accountKind, journal.accountId, SUM(journal.money) as sumM, SUM(journal.moneyDr) as sumMDr, SUM(journal.moneyCr) as sumMCr FROM e10doc_debs_journal as journal ';
		array_push ($q, 'LEFT JOIN e10doc_debs_accounts as accounts ON (journal.accountId = accounts.id AND accounts.docStateMain < 3)');
		array_push ($q, ' WHERE fiscalMonth = %i', $fiscalPeriod);

		if (count ($this->accKinds) !== 0)
			array_push ($q, ' AND accounts.accountKind IN %in', $this->accKinds);

		if ($this->centre !== FALSE)
			array_push ($q, '  AND centre = %i', $this->centre);

		array_push ($q, ' GROUP BY accountId');

		$data = [];
		$rows = $this->app->db()->query($q);
		forEach ($rows as $acc)
		{
			$data[] = $acc->toArray();
		}
		return $data;
	}

	function rowsYearSummary()
	{
		$fiscalPeriod = $this->param ('fiscalPeriod');
		$yearMonths = $this->fiscalMonths($fiscalPeriod);

		$q[] = 'SELECT accounts.accountKind as accountKind, journal.accountId, SUM(journal.money) as sumY, SUM(journal.moneyDr) as sumYDr, SUM(journal.moneyCr) as sumYCr FROM e10doc_debs_journal as journal ';
		array_push ($q, 'LEFT JOIN e10doc_debs_accounts as accounts ON (journal.accountId = accounts.id AND accounts.docStateMain < 3)');
		array_push ($q, ' WHERE fiscalType IN (0, 2) AND fiscalYear = %i', $this->fiscalPeriodRec['fiscalYear']);
		array_push ($q, ' AND fiscalMonth IN %in', $yearMonths);

		if (count ($this->accKinds) !== 0)
			array_push ($q, ' AND accounts.accountKind IN %in', $this->accKinds);

		if ($this->centre !== FALSE)
			array_push ($q, '  AND centre = %i', $this->centre);

		array_push ($q, ' GROUP BY accountId');

		$data = [];
		$rows = $this->app->db()->query($q);
		forEach ($rows as $acc)
		{
			$data[] = $acc->toArray();
		}
		return $data;
	}

	function fiscalMonths ($endMonth)
	{
		$endMonthRec = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE ndx = %i", $endMonth)->fetch ();
		$months = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE fiscalType IN (0, 2) AND fiscalYear = %i AND [globalOrder] <= %i",
			$endMonthRec['fiscalYear'], $endMonthRec['globalOrder']);

		$monthList = array();
		forEach ($months as $m)
			$monthList[] = $m['ndx'];

		return $monthList;
	}

	public function setAccKinds ($kinds)
	{
		$this->accKinds = $kinds;
	}
}

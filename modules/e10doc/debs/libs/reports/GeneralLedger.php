<?php

namespace e10doc\debs\libs\reports;
use e10\utils;

/**
 * Class GeneralLedger
 * @package e10doc\debs\libs\reports
 */
class GeneralLedger extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalPeriod = 0;
	public $fiscalYear = 0;
	public $accounts = [];

	var $data;
	var $dataAll;
	var $totals;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['openclose']]);
		parent::init();

		if ($this->fiscalPeriod === 0)
			$this->fiscalPeriod = $this->reportParams ['fiscalPeriod']['value'];
		if ($this->fiscalYear === 0)
			$this->fiscalYear = $this->reportParams ['fiscalPeriod']['values'][$this->fiscalPeriod]['fiscalYear'];

		$this->setInfo('title', 'Hlavní kniha');
		$this->setInfo('icon', 'report/generalLedger');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('saveFileName', 'Hlavní kniha '.str_replace(' ', '', $this->reportParams ['fiscalPeriod']['activeTitle']));

		$this->paperOrientation = 'landscape';
	}

	function fiscalMonthsGL ($endMonth)
	{
		$endMonthRec = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE ndx = %i", $endMonth)->fetch ();
		$months = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE fiscalType IN (0, 2) AND fiscalYear = %i AND [globalOrder] <= %i",
			$endMonthRec['fiscalYear'], $endMonthRec['globalOrder']);

		$monthList = array();
		forEach ($months as $m)
			$monthList[] = $m['ndx'];

		return $monthList;
	}

	function createContent_Data ()
	{
		$data = array ();

		// -- account names
		$qac = 'SELECT id, shortName FROM e10doc_debs_accounts WHERE docStateMain < 3';
		$accounts = $this->app->db()->query($qac);
		$accNames = $accounts->fetchPairs ('id', 'shortName');

		$qac = 'SELECT id, accountKind FROM e10doc_debs_accounts WHERE docStateMain < 3';
		$accKinds = $this->app->db()->query($qac)->fetchPairs ('id', 'accountKind');

		$qac = 'SELECT id, toBalance FROM e10doc_debs_accounts WHERE toBalance = 1 AND docStateMain < 3';
		$accToBalance = $this->app->db()->query($qac)->fetchPairs ('id', 'toBalance');

		$accNames ['ALL'] = 'CELKEM';

		// -- init states
		$q1[] = 'SELECT accountId, SUM(journal.moneyDr) as initStateDr, SUM(journal.moneyCr) as initStateCr FROM e10doc_debs_journal as journal ';
		array_push ($q1, ' WHERE fiscalType = 1 AND fiscalYear = %i', $this->fiscalYear);
		array_push ($q1, ' GROUP BY accountId');
		$accInitStates = $this->app->db()->query($q1);
		forEach ($accInitStates as $acc)
		{
			$accountId = $acc['accountId'];
			$data[$accountId] = $acc->toArray();
			$data[$accountId]['initState'] = $acc['initStateDr'] - $acc['initStateCr'];

			if (isset($accKinds[$accountId]))
				$data[$accountId]['accountKind'] = $accKinds[$accountId];
		}

		// -- month summary
		$q2[] = 'SELECT accountId, SUM(journal.money) as sumM, SUM(journal.moneyDr) as sumMDr, SUM(journal.moneyCr) as sumMCr FROM e10doc_debs_journal as journal ';
		array_push ($q2, ' WHERE fiscalMonth = %i', $this->fiscalPeriod);
		array_push ($q2, ' GROUP BY accountId');
		$accSumM = $this->app->db()->query($q2);
		forEach ($accSumM as $acc)
		{
			$accountId = $acc['accountId'];
			if (isset ($data[$accountId]))
			{
				$data[$accountId]['sumMCr'] = $acc['sumMCr'];
				$data[$accountId]['sumMDr'] = $acc['sumMDr'];
			}
			else
				$data[$accountId] = $acc->toArray();

			if (isset($accKinds[$accountId]))
				$data[$accountId]['accountKind'] = $accKinds[$accountId];
		}

		// -- year summary
		$yearMonths = $this->fiscalMonthsGL($this->fiscalPeriod);
		$q3[] = 'SELECT accountId, SUM(journal.money) as sumY, SUM(journal.moneyDr) as sumYDr, SUM(journal.moneyCr) as sumYCr FROM e10doc_debs_journal as journal ';
		array_push ($q3, ' WHERE fiscalType IN (0, 2) AND fiscalYear = %i', $this->fiscalYear);
		array_push ($q3, ' AND fiscalMonth IN %in', $yearMonths);
		array_push ($q3, ' GROUP BY accountId');
		$accSumY = $this->app->db()->query($q3);

		forEach ($accSumY as $acc)
		{
			$accountId = $acc['accountId'];
			if (isset ($data[$accountId]))
			{
				$data[$accountId]['sumYCr'] = $acc['sumYCr'];
				$data[$accountId]['sumYDr'] = $acc['sumYDr'];
			}
			else
			{
				$data[$accountId] = $acc->toArray();
			}
			if (isset($accKinds[$accountId]))
				$data[$accountId]['accountKind'] = $accKinds[$accountId];
		}


		// totals and end states
		$totals = array ();
		forEach ($data as &$acc)
		{
			$accountId = $acc['accountId'];
			$endState = 0.0;
			if (isset ($acc['initState'])) $endState += $acc['initState'];
			if (isset ($acc['sumYDr'])) $endState += $acc['sumYDr'];
			if (isset ($acc['sumYCr'])) $endState -= $acc['sumYCr'];
			$acc['endState'] = $endState;
			$acc['title'] = isset($accNames[$accountId])?$accNames[$accountId]:'';

			$sumIds = array ('ALL', substr ($acc['accountId'], 0, 1), substr ($acc['accountId'], 0, 2), substr ($acc['accountId'], 0, 3));
			forEach ($sumIds as $sumId)
			{
				if (!isset ($totals[$sumId]))
					$totals[$sumId] = array ('accountId' => $sumId, 'initState' => 0.0, 'endState' => 0.0,
						'sumMCr' => 0.0, 'sumMDr' => 0.0, 'sumYCr' => 0.0, 'sumYDr' => 0.0,
						'title' => isset($accNames[$sumId])?$accNames[$sumId]:'', 'accGroup' => TRUE,
						'_options' => array ('class' => 'subtotal'));

				if (isset ($acc['initState'])) $totals[$sumId]['initState'] += $acc['initState'];
				if (isset ($acc['endState'])) $totals[$sumId]['endState'] += $acc['endState'];

				if (isset ($acc['sumMCr'])) $totals[$sumId]['sumMCr'] += $acc['sumMCr'];
				if (isset ($acc['sumMDr'])) $totals[$sumId]['sumMDr'] += $acc['sumMDr'];

				if (isset ($acc['sumYCr'])) $totals[$sumId]['sumYCr'] += $acc['sumYCr'];
				if (isset ($acc['sumYDr'])) $totals[$sumId]['sumYDr'] += $acc['sumYDr'];
			}
		}


		$allAccountsSorted = \E10\sortByOneKey ($data, 'accountId');
		$all = array ();
		//$lastSumIds = array ('_', '_', '_', '_');
		forEach ($allAccountsSorted as $acc)
		{
			$accountId = $acc['accountId'];
			$sumIds = array (substr ($acc['accountId'], 0, 3), substr ($acc['accountId'], 0, 2), substr ($acc['accountId'], 0, 1), 'ALL');

			if (isset ($lastSumIds))
			{
				for ($i = 0; $i < 3; $i++)
				{
					if ($lastSumIds [$i] != $sumIds[$i])
						$all [] = $totals[$lastSumIds [$i]];
				}
			}
			else $lastSumIds = array ();

			if (isset ($acc['accountKind']) && $acc['accountKind'] == 5)
				$acc['accountKind'] = ($acc['endState'] < 0.0) ? 1 : 0;
			if (isset ($accToBalance[$acc['accountId']]))
				$acc['toBalance'] = 1;
			$acc['accGroup'] = FALSE;

			$all [] = $acc;

			for ($i = 0; $i < 3; $i++)
				$lastSumIds [$i] = $sumIds[$i];

			if (!isset ($this->accounts[$accountId]))
				$this->accounts[$accountId] = isset($accNames[$accountId]) ? $accNames[$accountId] : 'NEEXISTUJÍCÍ ÚČET';
		}

		if (count($totals) !== 0)
		{
			for ($i = 0; $i < 3; $i++)
				$all [] = $totals[$lastSumIds [$i]];

			$totals['ALL']['accountId'] = 'Σ';
			$totals['ALL']['accGroup'] = TRUE;
			$totals['ALL']['_options']['class'] = 'sumtotal';
			$totals['ALL']['_options']['beforeSeparator'] = 'separator';
			$all [] = $totals['ALL'];
		}

		$this->totals = $totals;
		$this->data = $data;
		$this->dataAll = $all;

		return $all;
	}

	function createContent ()
	{
		$data = $this->createContent_Data ();

		$h = array ('accountId' => 'Účet',
			'initState' => ' Poč. stav',
			'sumMDr' => ' Obrat MD/měsíc', 'sumMCr' => ' Obrat DAL/měsíc',
			'sumYDr' => ' Obrat MD/rok', 'sumYCr' => ' Obrat DAL/rok',
			'endState' => ' Zůstatek', 'title' => 'Text');

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));
	}
}

<?php

namespace e10doc\debs\libs\reports;


/**
 * class VatAccReport
 */
class VatAccReport extends \e10doc\core\libs\reports\GlobalReport
{
  var $vatPeriod = 0;
  var $dateBegin = NULL;
  var $dateEnd = NULL;

	var $accounts = [];

	var $data;
	var $dataAll;
	var $totals;

	function init ()
	{
		$this->addParam ('vatPeriod', 'vatPeriod');
		parent::init();

		if ($this->vatPeriod === 0)
			$this->vatPeriod = $this->reportParams ['vatPeriod']['value'];

    if ($this->dateBegin === NULL)
      $this->dateBegin = $this->reportParams ['vatPeriod']['values'][$this->vatPeriod]['dateBegin'];
    if ($this->dateEnd === NULL)
      $this->dateEnd = $this->reportParams ['vatPeriod']['values'][$this->vatPeriod]['dateEnd'];

		$this->setInfo('title', 'Vyúčtování DPH');
		$this->setInfo('icon', 'report/VAT');
		$this->setInfo('param', 'Období DPH', $this->reportParams ['vatPeriod']['activeTitle']);
		$this->setInfo('saveFileName', 'Vyúčtování DPH '.str_replace(' ', '', $this->reportParams ['vatPeriod']['activeTitle']));

		$this->paperOrientation = 'landscape';
	}

	function createContent_Data ()
	{
		$data = [];

		// -- account names
		$qac = 'SELECT id, shortName FROM e10doc_debs_accounts WHERE docStateMain < 3';
		$accounts = $this->app->db()->query($qac);
		$accNames = $accounts->fetchPairs ('id', 'shortName');

		$qac = 'SELECT id, accountKind FROM e10doc_debs_accounts WHERE docStateMain < 3';
		$accKinds = $this->app->db()->query($qac)->fetchPairs ('id', 'accountKind');

		$qac = 'SELECT id, toBalance FROM e10doc_debs_accounts WHERE toBalance = 1 AND docStateMain < 3';
		$accToBalance = $this->app->db()->query($qac)->fetchPairs ('id', 'toBalance');

		$accNames ['ALL'] = 'CELKEM';

		// -- period summary
		$q2 = [];
		array_push ($q2, 'SELECT accountId,');
		array_push ($q2, ' SUM(journal.money) as sumM, SUM(journal.moneyDr) as sumMDr, SUM(journal.moneyCr) as sumMCr');
		array_push ($q2, ' FROM e10doc_debs_journal AS journal ');
		array_push ($q2, ' LEFT JOIN e10doc_core_heads AS heads ON journal.document = heads.ndx');
		array_push ($q2, ' WHERE (heads.dateTax >= %d', $this->dateBegin, ' AND heads.dateTax <= %d', $this->dateEnd, ')');
		array_push ($q2, ' AND fiscalType = %i', 0);
    array_push ($q2, ' AND accountId LIKE %s', '343%');
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

		// -- totals and end states
		$totals = [];
		forEach ($data as &$acc)
		{
			$accountId = $acc['accountId'];
			$endState = 0.0;
			if (isset ($acc['initState'])) $endState += $acc['initState'];
			if (isset ($acc['sumMDr'])) $endState += $acc['sumMDr'];
			if (isset ($acc['sumMCr'])) $endState -= $acc['sumMCr'];
			$acc['endState'] = $endState;
			$acc['title'] = isset($accNames[$accountId])?$accNames[$accountId]:'';

			$sumIds = ['ALL'];
			forEach ($sumIds as $sumId)
			{
				if (!isset ($totals[$sumId]))
				{
					$totals[$sumId] = [
						'accountId' => $sumId, 'initState' => 0.0, 'endState' => 0.0,
						'sumMCr' => 0.0, 'sumMDr' => 0.0,
						'title' => isset($accNames[$sumId])?$accNames[$sumId]:'', 'accGroup' => TRUE,
						'_options' => ['class' => 'subtotal']
					];
				}

				if (isset ($acc['initState'])) $totals[$sumId]['initState'] += $acc['initState'];
				if (isset ($acc['endState'])) $totals[$sumId]['endState'] += $acc['endState'];

				if (isset ($acc['sumMCr'])) $totals[$sumId]['sumMCr'] += $acc['sumMCr'];
				if (isset ($acc['sumMDr'])) $totals[$sumId]['sumMDr'] += $acc['sumMDr'];
			}
		}


		$allAccountsSorted = \E10\sortByOneKey ($data, 'accountId');
		$all = [];
		forEach ($allAccountsSorted as $acc)
		{
			$accountId = $acc['accountId'];
			$sumIds = [];

			if (isset ($acc['accountKind']) && $acc['accountKind'] == 5)
				$acc['accountKind'] = ($acc['endState'] < 0.0) ? 1 : 0;
			if (isset ($accToBalance[$acc['accountId']]))
				$acc['toBalance'] = 1;
			$acc['accGroup'] = FALSE;

			$all [] = $acc;

			if (!isset ($this->accounts[$accountId]))
				$this->accounts[$accountId] = isset($accNames[$accountId]) ? $accNames[$accountId] : 'NEEXISTUJÍCÍ ÚČET';
		}

		if (count($totals) !== 0)
		{
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

		$h = [
			'accountId' => 'Účet',
			'sumMDr' => ' Obrat MD', 'sumMCr' => ' Obrat DAL',
			'endState' => ' Zůstatek', 'title' => 'Text'
		];

		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE));
	}
}

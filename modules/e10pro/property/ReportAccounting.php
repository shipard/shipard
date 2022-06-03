<?php

namespace e10pro\property;
use \e10\utils, e10pro\property\TableProperty, e10\uiutils;
use E10Doc\Core\e10utils;


/**
 * Class ReportPropertyAccounting
 */
class ReportAccounting extends \e10pro\property\ReportDepreciations
{
	/** @var  \e10\DbTable */
	var $tableDebsGroups;
	var $accounting = [];

	var $propertyReport;
	var $glr = NULL;

	public function createContent()
	{
		$this->tableDebsGroups = $this->app->table('e10doc.debs.groups');

		$this->loadData ();
		$this->createAccounting ();

		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum(); break;
			case 'deps': $this->createContent_Deps(); break;
			case 'increase': $this->createContent_Increase(); break;
			case 'decrease': $this->createContent_Decrease(); break;
		}
	}

	function createContent_Sum ()
	{
		$this->setInfo('title', 'Přehled účtování majetku');

		$h = ['accountId' => 'Účet', 'moneyDr' => '+Částka MD', 'moneyCr' => '+Částka DAL', 'text' => 'Text'];
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->accounting ['deps']['accTotals'], 'title' => 'Odpisy', 'main' => TRUE]);

		$h = ['accountId' => 'Účet', 'moneyDr' => '+Částka MD', 'moneyCr' => '+Částka DAL', 'text' => 'Text'];
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->accounting ['decrease']['accTotals'], 'title' => 'Úbytky', 'main' => TRUE]);

		$h = ['accountId' => 'Účet', 'moneyDr' => '+Částka MD', 'moneyCr' => '+Částka DAL', 'text' => 'Text'];
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->accounting ['increase']['accTotals'], 'title' => 'Přírustky', 'main' => TRUE]);

		$this->createContent_Sum_Totals();

		$this->setInfo('icon', 'report/accounting');
	}

	function createContent_Sum_Totals ()
	{
		$tt = [];
		foreach ($this->accounting as $keyPart => $totals)
		{
			foreach ($totals['accTotals'] as $accKey => $acc)
			{
				$ak = $acc['accountId'];

				if (!isset($tt[$ak]))
				{
					$tt[$ak] = ['moneyDr' => 0.0, 'moneyCr' => 0.0, 'accountId' => $acc['accountId']];
				}

				if (isset($acc['moneyDr']))
					$tt[$ak]['moneyDr'] += $acc['moneyDr'];
				if (isset($acc['moneyCr']))
					$tt[$ak]['moneyCr'] += $acc['moneyCr'];
			}
		}

		foreach ($tt as $accountId => $acc)
		{
			$accE10 = $this->getAccountBalanceGL ($accountId, $this->fiscalYear);
			$tt[$accountId]['moneyCrGL'] = $accE10['sumYCr'];
			$tt[$accountId]['moneyDrGL'] = $accE10['sumYDr'];

			if (abs($tt[$accountId]['moneyCrGL'] - $tt[$accountId]['moneyCr']) < 2)
				$cc = 'e10-row-plus';
			else
				$cc = 'e10-row-minus';
			$tt[$accountId]['_options']['cellClasses']['moneyCrGL'] = $cc;
			$tt[$accountId]['_options']['cellClasses']['moneyCr'] = $cc;

			if (abs($tt[$accountId]['moneyDrGL'] - $tt[$accountId]['moneyDr']) < 2)
				$cc = 'e10-row-plus';
			else
				$cc = 'e10-row-minus';
			$tt[$accountId]['_options']['cellClasses']['moneyDrGL'] = $cc;
			$tt[$accountId]['_options']['cellClasses']['moneyDr'] = $cc;
		}

		$h = [
				'accountId' => 'Účet',
				'moneyDr' => '+Obrat MD', 'moneyDrGL' => '+Obrat MD HK',
				'moneyCr' => '+Obrat DAL', 'moneyCrGL' => '+Obrat DAL HK',
		];
		ksort($tt);
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $tt, 'title' => 'CELKEM', 'main' => TRUE]);

		$this->createContent_Test();
	}

	function createContent_Test ()
	{
		$this->propertyReport = new \e10pro\property\ReportDepreciations ($this->app);
		$this->propertyReport->fiscalYear = $this->fiscalYear;
		$this->propertyReport->groupBy = 'debsAccounts';

		$this->propertyReport->init();
		$this->propertyReport->renderReport();
		$this->propertyReport->createReport();


		$accounts = [];
		$accounts['013'] = $this->getAccountBalanceGL ('013', $this->fiscalYear);
		$accounts['021'] = $this->getAccountBalanceGL ('021', $this->fiscalYear);
		$accounts['022'] = $this->getAccountBalanceGL ('022', $this->fiscalYear);
		$accounts['073'] = $this->getAccountBalanceGL ('073', $this->fiscalYear);
		$accounts['081'] = $this->getAccountBalanceGL ('081', $this->fiscalYear);
		$accounts['082'] = $this->getAccountBalanceGL ('082', $this->fiscalYear);


		$tt = [];
		$tt[] = ['accountId' => '013', 'balance' => $accounts['013']['endState']];
		$tt[] = ['accountId' => '021', 'balance' => $accounts['021']['endState']];
		$tt[] = ['accountId' => '022', 'balance' => $accounts['022']['endState']];
		$tt[] = ['accountId' => '013 + 021 + 022',
				'balance' => $accounts['021']['endState'] + $accounts['022']['endState'] + $accounts['013']['endState'], '_options' => ['class' => 'subtotal'],
				'propertyAmount' => $this->propertyReport->totals['balance']['priceIn'],
				'diff' => ($accounts['021']['endState'] + $accounts['022']['endState'] + $accounts['013']['endState']) - $this->propertyReport->totals['balance']['priceIn'],
		];

		$tt[] = ['accountId' => '073', 'balance' => $accounts['073']['endState']];
		$tt[] = ['accountId' => '081', 'balance' => $accounts['081']['endState']];
		$tt[] = ['accountId' => '082', 'balance' => $accounts['082']['endState']];
		$tt[] = ['accountId' => '073 + 081 + 082',
				'balance' => $accounts['081']['endState'] + $accounts['082']['endState'] + $accounts['073']['endState'], '_options' => ['class' => 'subtotal'],
		];

		$tt[] = [
				'accountId' => 'Zůstatková hodnota',
				'balance' => ($accounts['021']['endState'] + $accounts['022']['endState'] + $accounts['013']['endState']) + ($accounts['081']['endState'] + $accounts['082']['endState'] + $accounts['073']['endState']),
				'propertyAmount' => $this->propertyReport->totals['balance']['acc'],
				'diff' => (($accounts['021']['endState'] + $accounts['022']['endState'] + $accounts['013']['endState']) + ($accounts['081']['endState'] + $accounts['082']['endState'] + $accounts['073']['endState'])) - $this->propertyReport->totals['balance']['acc'],
				'_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator']
		];

		$h = [
				'accountId' => 'Účet',
				'balance' => ' Zůstatek HK',
				'propertyAmount' => ' Evidence majetku',
				'diff' => ' Rozdíl',
		];
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $tt, 'title' => 'KONTROLA', 'main' => TRUE]);
	}

	function createContent_Deps ()
	{
		$t = $this->accounting ['deps']['journal'];

		$h = [
				'#' => '#', 'propertyId' => 'InvČ', 'accountId' => 'Účet',
				'moneyDr' => ' Částka MD', 'moneyCr' => ' Částka DAL', 'text' => 'Text'
		];

		$first = TRUE;
		foreach ($this->accounting ['deps']['accTotals'] as $total)
		{
			$total['_options'] = ['class' => 'subtotal'];
			if ($first)
				$total['_options']['beforeSeparator'] = 'separator';
			$t [] = $total;
			$first = FALSE;
		}

		$this->accounting ['deps']['totals']['_options'] = ['class' => 'sumtotal', 'beforeSeparator' => 'separator'];
		$t [] = $this->accounting ['deps']['totals'];

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);


		$this->setInfo('icon', 'tables/e10pro.property.depreciation');
		$this->setInfo('title', 'Účtování odpisů majetku');
		$this->paperOrientation = 'landscape';
	}

	function createContent_Increase ()
	{
		$t = $this->accounting ['increase']['journal'];

		$h = [
				'#' => '#', 'propertyId' => 'InvČ', 'accountId' => 'Účet',
				'moneyDr' => ' Částka MD', 'moneyCr' => ' Částka DAL', 'text' => 'Text'
		];

		$first = TRUE;
		foreach ($this->accounting ['increase']['accTotals'] as $total)
		{
			$total['_options'] = ['class' => 'subtotal'];
			if ($first)
				$total['_options']['beforeSeparator'] = 'separator';
			$t [] = $total;
			$first = FALSE;
		}

		$this->accounting ['increase']['totals']['_options'] = ['class' => 'sumtotal', 'beforeSeparator' => 'separator'];
		$t [] = $this->accounting ['increase']['totals'];

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);


		$this->setInfo('icon', 'detailReportIncrements');
		$this->setInfo('title', 'Účtování přírustků majetku');
		$this->paperOrientation = 'landscape';
	}

	function createContent_Decrease ()
	{
		$t = $this->accounting ['decrease']['journal'];

		$h = [
				'#' => '#', 'propertyId' => 'InvČ', 'accountId' => 'Účet',
				'moneyDr' => ' Částka MD', 'moneyCr' => ' Částka DAL', 'text' => 'Text'
		];

		$first = TRUE;
		foreach ($this->accounting ['decrease']['accTotals'] as $total)
		{
			$total['_options'] = ['class' => 'subtotal'];
			if ($first)
				$total['_options']['beforeSeparator'] = 'separator';
			$t [] = $total;
			$first = FALSE;
		}

		$this->accounting ['decrease']['totals']['_options'] = ['class' => 'sumtotal', 'beforeSeparator' => 'separator'];
		$t [] = $this->accounting ['decrease']['totals'];

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);


		$this->setInfo('icon', 'detailReportDepletions');
		$this->setInfo('title', 'Účtování úbytků majetku');
		$this->paperOrientation = 'landscape';
	}

	function createAccounting ()
	{
		$this->createAccounting_Deps();
		$this->createAccounting_Increase();
		$this->createAccounting_Decrease();
	}

	function createAccounting_Deps ()
	{
		$this->accounting ['deps']['totals'] = ['moneyDr' => 0.0, 'moneyCr' => 0];

		foreach ($this->data as $propertyNdx => $p)
		{
			if ($p['accDep'])
			{
				$accDepsDebit = $this->propertyAccountId($p, 'debsAccPropIdDepDebit');
				$accDepsCredit = $this->propertyAccountId($p, 'debsAccPropIdDepCredit');

				$accRowDr = [
						'propertyId' => $p['propertyId'], 'text' => $p['fullName'],
						'accountDrId' => $accDepsDebit, 'accountId' => $accDepsDebit, 'moneyDr' => $p['accDep'], 'money' => $p['accDep']
				];
				$accRowCr = [
						'propertyId' => $p['propertyId'], 'text' => $p['fullName'],
						'accountCrId' => $accDepsCredit, 'accountId' => $accDepsCredit, 'moneyCr' => $p['accDep'], 'money' => $p['accDep']
				];

				$this->accounting ['deps']['journal'][] = $accRowDr;
				$this->accounting ['deps']['journal'][] = $accRowCr;

				if (!isset($this->accounting ['deps']['accTotals']['DR.'.$accDepsDebit]))
					$this->accounting ['deps']['accTotals']['DR.'.$accDepsDebit] = ['accountDrId' => $accDepsDebit, 'accountId' => $accDepsDebit, 'moneyDr' => 0.0];
				$this->accounting ['deps']['accTotals']['DR.'.$accDepsDebit]['moneyDr'] += $p['accDep'];

				if (!isset($this->accounting ['deps']['accTotals']['CR.'.$accDepsCredit]))
					$this->accounting ['deps']['accTotals']['CR.'.$accDepsCredit] = ['accountCrId' => $accDepsCredit, 'accountId' => $accDepsCredit, 'moneyCr' => 0.0];
				$this->accounting ['deps']['accTotals']['CR.'.$accDepsCredit]['moneyCr'] += $p['accDep'];

				$this->accounting ['deps']['totals']['moneyDr'] += $p['accDep'];
				$this->accounting ['deps']['totals']['moneyCr'] += $p['accDep'];
			}
		}
	}

	function createAccounting_Increase ()
	{
		$this->accounting ['increase']['totals'] = ['moneyDr' => 0.0, 'moneyCr' => 0];

		if (isset($this->dataIncrease['lt']) && count($this->dataIncrease['lt']))
		{
			foreach ($this->dataIncrease['lt'] as $p)
			{
				$accDebit = $this->propertyAccountId($p, 'debsAccPropIdProperty');
				$accCredit = $this->propertyAccountId($p, 'debsAccPropIdInclusion');

				$accRowDr = [
						'propertyId' => $p['propertyId'], 'text' => $p['fullName'],
						'accountDrId' => $accDebit, 'accountId' => $accDebit, 'moneyDr' => $p['priceIn'], 'money' => $p['priceIn']
				];
				$accRowCr = [
						'propertyId' => $p['propertyId'], 'text' => $p['fullName'],
						'accountCrId' => $accCredit, 'accountId' => $accCredit, 'moneyCr' => $p['priceIn'], 'money' => $p['priceIn']
				];

				$this->accounting ['increase']['journal'][] = $accRowDr;
				$this->accounting ['increase']['journal'][] = $accRowCr;

				if (!isset($this->accounting ['increase']['accTotals']['DR.'.$accDebit]))
					$this->accounting ['increase']['accTotals']['DR.'.$accDebit] = ['accountDrId' => $accDebit, 'accountId' => $accDebit, 'moneyDr' => 0.0];
				$this->accounting ['increase']['accTotals']['DR.'.$accDebit]['moneyDr'] += $p['priceIn'];

				if (!isset($this->accounting ['increase']['accTotals']['CR.'.$accCredit]))
					$this->accounting ['increase']['accTotals']['CR.'.$accCredit] = ['accountCrId' => $accCredit, 'accountId' => $accCredit, 'moneyCr' => 0.0];
				$this->accounting ['increase']['accTotals']['CR.'.$accCredit]['moneyCr'] += $p['priceIn'];


				$this->accounting ['increase']['totals']['moneyDr'] += $p['priceIn'];
				$this->accounting ['increase']['totals']['moneyCr'] += $p['priceIn'];
			}
		}
	}

	function createAccounting_Decrease ()
	{
		$this->accounting ['decrease']['totals'] = ['moneyDr' => 0.0, 'moneyCr' => 0];

		if (isset($this->dataDecrease['lt']) && count($this->dataDecrease['lt']))
		{
			foreach ($this->dataDecrease['lt'] as $p)
			{
				// -- 541 x 082
				$accDebit = /*$this->propertyAccountId($p, 'debsAccPropIdDepDebit')*/'541100';
				$accCredit = $this->propertyAccountId($p, 'debsAccPropIdDepCredit');

				$accRowDr = [
						'propertyId' => $p['propertyId'], 'text' => $p['fullName'],
						'accountDrId' => $accDebit, 'accountId' => $accDebit, 'moneyDr' => $p['accBalance'], 'money' => $p['accBalance']
				];
				$accRowCr = [
						'propertyId' => $p['propertyId'], 'text' => $p['fullName'],
						'accountCrId' => $accCredit, 'accountId' => $accCredit, 'moneyCr' => $p['accBalance'], 'money' => $p['accBalance']
				];

				$this->accounting ['decrease']['journal'][] = $accRowDr;
				$this->accounting ['decrease']['journal'][] = $accRowCr;

				if (!isset($this->accounting ['decrease']['accTotals']['DR.'.$accDebit]))
					$this->accounting ['decrease']['accTotals']['DR.'.$accDebit] = ['accountDrId' => $accDebit, 'accountId' => $accDebit, 'moneyDr' => 0.0];
				$this->accounting ['decrease']['accTotals']['DR.'.$accDebit]['moneyDr'] += $p['accBalance'];

				if (!isset($this->accounting ['decrease']['accTotals']['CR.'.$accCredit]))
					$this->accounting ['decrease']['accTotals']['CR.'.$accCredit] = ['accountCrId' => $accCredit, 'accountId' => $accCredit, 'moneyCr' => 0.0];
				$this->accounting ['decrease']['accTotals']['CR.'.$accCredit]['moneyCr'] += $p['accBalance'];

				$this->accounting ['decrease']['totals']['moneyDr'] += $p['accBalance'];
				$this->accounting ['decrease']['totals']['moneyCr'] += $p['accBalance'];


				// -- 082 x 022
				$accDebit = $this->propertyAccountId($p, 'debsAccPropIdDepCredit');
				$accCredit = $this->propertyAccountId($p, 'debsAccPropIdProperty');

				$accRowDr = [
						'propertyId' => $p['propertyId'], 'text' => $p['fullName'],
						'accountDrId' => $accDebit, 'accountId' => $accDebit, 'moneyDr' => $p['priceIn'], 'money' => $p['priceIn']
				];
				$accRowCr = [
						'propertyId' => $p['propertyId'], 'text' => $p['fullName'],
						'accountCrId' => $accCredit, 'accountId' => $accCredit, 'moneyCr' => $p['priceIn'], 'money' => $p['priceIn']
				];

				$this->accounting ['decrease']['journal'][] = $accRowDr;
				$this->accounting ['decrease']['journal'][] = $accRowCr;

				if (!isset($this->accounting ['decrease']['accTotals']['DR.'.$accDebit]))
					$this->accounting ['decrease']['accTotals']['DR.'.$accDebit] = ['accountDrId' => $accDebit, 'accountId' => $accDebit, 'moneyDr' => 0.0];
				$this->accounting ['decrease']['accTotals']['DR.'.$accDebit]['moneyDr'] += $p['priceIn'];

				if (!isset($this->accounting ['decrease']['accTotals']['CR.'.$accCredit]))
					$this->accounting ['decrease']['accTotals']['CR.'.$accCredit] = ['accountCrId' => $accCredit, 'accountId' => $accCredit, 'moneyCr' => 0.0];
				$this->accounting ['decrease']['accTotals']['CR.'.$accCredit]['moneyCr'] += $p['priceIn'];

				$this->accounting ['decrease']['totals']['moneyDr'] += $p['priceIn'];
				$this->accounting ['decrease']['totals']['moneyCr'] += $p['priceIn'];
			}
		}
	}

	function propertyAccountId ($propertyRecData, $accType)
	{
		$acc = '';
		$a = '100';

		$debsGroup = $this->tableDebsGroups->loadItem ($propertyRecData['debsGroup']);
		if ($debsGroup)
		{
			$acc = $debsGroup[$accType];

			if ($debsGroup['analytics'] !== '')
				$a = $debsGroup['analytics'];
		}

		if ($acc === '')
		{
			switch ($accType)
			{
				case 'debsAccPropIdProperty': $acc = '022'.$a; break;
				case 'debsAccPropIdInclusion': $acc = '042'.$a; break;
				case 'debsAccPropIdEnhancement': $acc = '042'.$a; break;
				case 'debsAccPropIdDepDebit': $acc = '551'.$a; break;
				case 'debsAccPropIdDepCredit': $acc = '082'.$a; break;
			}
		}

		return $acc;
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icon' => 'detailReportSum', 'title' => 'Sumárně'];
		$d[] = ['id' => 'deps', 'icon' => 'detailReportTaxDepreciations', 'title' => 'Odpisy'];
		$d[] = ['id' => 'increase', 'icon' => 'detailReportIncrements', 'title' => 'Přírustky'];
		$d[] = ['id' => 'decrease', 'icon' => 'detailReportDepletions', 'title' => 'Úbytky'];
		return $d;
	}

	public function getAccountBalanceGL ($account, $fiscalYear, $fiscalMonth = -1)
	{
		if (!$this->glr)
		{
			if ($fiscalMonth === -1)
			{
				$date = $this->reportParams ['fiscalYear']['values'][$fiscalYear]['dateEnd'];
				$fiscalMonth = e10utils::todayFiscalMonth($this->app, $date);
			}
			$this->glr = new \e10doc\debs\libs\reports\GeneralLedger ($this->app);
			$this->glr->fiscalYear = $fiscalYear;
			$this->glr->fiscalPeriod = $fiscalMonth;
			$this->glr->createContent_Data();

			//error_log("-------".json_encode($this->glr->data['022001']));
		}

		if (isset($this->glr->totals[$account]))
			return $this->glr->totals[$account];

		if (isset($this->glr->data[$account]))
			return $this->glr->data[$account];

		return ['sumYCr' => 0.0, 'sumYDr' => 0.0, 'endState' => 0.0];
	}

}

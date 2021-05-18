<?php

namespace e10pro\property;
use \e10\utils, e10pro\property\TableProperty, e10\uiutils;


/**
 * Class ReportNonDeps
 * @package e10pro\property
 */
class ReportNonDeps extends \e10pro\property\ReportDepreciations
{
	function init ()
	{
		$this->propertyCategories = [TableProperty::pcLongTermLanded];
		$this->groupByEnum = ['-' => '-', 'types' => 'Typu majetku', 'debsAccounts' => 'Účtů'];
		parent::init();
	}

	function createContent_Sum ()
	{
		$t = [];

		if ($this->groupBy === '-')
		{
			$this->setInfo('title', 'Neodepisovaný majetek');
			foreach ($this->data as $propertyNdx => $p)
			{
				$none = [];
				$this->createContent_Sum_AddPart_Item ($t, $propertyNdx, $none, 0);
			}
		}
		elseif ($this->groupBy === 'types')
		{
			$this->setInfo('title', 'Neodepisovaný majetek podle typu');
			foreach (\E10\sortByOneKey($this->groupByTypes, 'name', TRUE) as $typeNdx => $type)
			{
				$this->createContent_Sum_AddPart_Title ($t, $type['name']);
				foreach ($type['items'] as $propertyNdx)
					$this->createContent_Sum_AddPart_Item ($t, $propertyNdx, $this->groupByTypes, $typeNdx);
				$this->createContent_Sum_AddPart_Total ($t, $this->groupByTypes, $typeNdx);
			}
		}
		elseif ($this->groupBy === 'depsGroups')
		{ // TODO: remove?
			$this->setInfo('title', 'Neodepisovaný majetek podle odpisových skupin');
			foreach (\E10\sortByOneKey($this->groupByDepsGroups, 'order', TRUE) as $depGroupId => $depGroup)
			{
				$this->createContent_Sum_AddPart_Title ($t, $depGroup['name']);
				foreach ($depGroup['items'] as $propertyNdx)
					$this->createContent_Sum_AddPart_Item ($t, $propertyNdx, $this->groupByDepsGroups, $depGroupId);
				$this->createContent_Sum_AddPart_Total ($t, $this->groupByDepsGroups, $depGroupId);
			}
		}
		elseif ($this->groupBy === 'debsAccounts')
		{
			$this->setInfo('title', 'Neodepisovaný majetek podle účtů');
			foreach (\E10\sortByOneKey($this->groupByDebsAccounts, 'name', TRUE) as $accountId => $account)
			{
				$this->createContent_Sum_AddPart_Title ($t, $account['name']);
				foreach ($account['items'] as $propertyNdx)
					$this->createContent_Sum_AddPart_Item ($t, $propertyNdx, $this->groupByDebsAccounts, $accountId);
				$this->createContent_Sum_AddPart_Total ($t, $this->groupByDebsAccounts, $accountId);
			}
		}

		$total = [
				'propertyId' => 'CELKEM',
				'priceIn' => $this->totals['all']['priceIn'],
				'priceBegin' => $this->totals['all']['priceBegin'],
				'priceIncrease' => $this->totals['all']['priceIncrease'],
				'priceDecrease' => $this->totals['all']['priceDecrease'],

				'_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 2]],
		];

		$t[] = $total;

		$this->totals['balance']['priceIn'] = $this->totals['all']['priceIn'];

		if (isset($this->totals['decreased']['priceIn']) && $this->totals['decreased']['priceIn'])
		{
			$totalDecreased = [
					'propertyId' => 'Vyřazeno',
					'priceIn' => $this->totals['decreased']['priceIn'],
					'priceBegin' => $this->totals['decreased']['priceBegin'],
					'priceIncrease' => $this->totals['decreased']['priceIncrease'],
					'priceDecrease' => $this->totals['decreased']['priceDecrease'],

					'_options' => ['class' => 'sumtotal e10-row-minus', 'ccbeforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 2]],
			];
			$t[] = $totalDecreased;
			$totalBalance = [
					'propertyId' => 'Zůstatek',
					'priceIn' => $this->totals['all']['priceIn'] - $this->totals['decreased']['priceIn'],
					'priceBegin' => $this->totals['all']['priceBegin'] - $this->totals['decreased']['priceBegin'],
					'priceIncrease' => $this->totals['all']['priceIncrease'] - $this->totals['decreased']['priceIncrease'],
					'priceDecrease' => $this->totals['all']['priceDecrease'] - $this->totals['decreased']['priceDecrease'],
					'_options' => ['class' => 'sumtotal e10-row-plus', 'ccbeforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 2]],
			];
			$t[] = $totalBalance;

			$this->totals['balance']['priceIn'] -= $this->totals['decreased']['priceIn'];
		}

		if ($this->subReportId === 'balances')
		{ // TODO: remove
			$h = [
					'#' => '#', 'propertyId' => 'InvČ', 'fullName' => 'Název', 'dg' => ' Sk.',
					'dateInclusion' => 'Zařazeno', 'priceIn' => ' Poř. cena',
				//'accDep' => ' Úč. odpis', 'taxDep' => ' Daň. odpis', 'diffDep' => ' Rozdíl ÚO - DO',
					'valThisTax' => ' Daň. oprávky', 'taxBalanceThis' => ' Daň. ZC',
					'valThisAcc' => ' Úč. oprávky', 'accBalanceThis' => ' Úč. ZC',
					'diffAccTaxBalance' => ' ÚZC - DZC'
			];
		}
		else
		{
			$h = [
					'#' => '#', 'propertyId' => 'InvČ', 'fullName' => 'Název',
					'dateInclusion' => 'Zařazeno',
					'priceBegin' => ' Vstupní cena',
					'priceIncrease' => ' Navýšení ceny',
					'priceDecrease' => ' Snížení ceny',
					'priceIn' => ' Zůst. cena'
			];
		}
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);


		$this->setInfo('icon', 'icon-chevron-circle-down');
		$this->paperOrientation = 'landscape';
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icon' => 'detailReportSum', 'title' => 'Sumárně'];
		/*
		$d[] = ['id' => 'balances', 'icon' => 'icon-check-square', 'title' => 'Zůstatky'];
		$d[] = ['id' => 'cards1', 'icon' => 'icon-file-o', 'title' => 'Karty 1'];
		$d[] = ['id' => 'cards2', 'icon' => 'icon-file-text-o', 'title' => 'Karty 2'];
		$d[] = ['id' => 'cards3', 'icon' => 'icon-file-text', 'title' => 'Karty 3'];
		$d[] = ['id' => 'dt', 'icon' => 'icon-shield', 'title' => 'Odložená daň'];
		$d[] = ['id' => 'increase', 'icon' => 'icon-plus-square', 'title' => 'Přírustky'];
		$d[] = ['id' => 'decrease', 'icon' => 'icon-minus-square', 'title' => 'Úbytky'];
		*/
		return $d;
	}

}



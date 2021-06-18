<?php

namespace e10pro\property;


use \e10\utils, e10pro\property\TableProperty, e10\uiutils;


/**
 * Class ReportDepreciations
 * @package e10pro\property
 */
class ReportDepreciations extends \e10doc\core\libs\reports\GlobalReport
{
	var $propertyCategories = [TableProperty::pcLongTermTangible, TableProperty::pcLongTermIntangible];
	var $groupByEnum = ['-' => '-', 'types' => 'Typu majetku', 'depsGroups' => 'Odpisových skupin', 'debsAccounts' => 'Účtů'];

	var $tableProperty;
	var $depsGroups;

	var $fiscalYear = 0;
	var $periodBegin;
	var $periodEnd;
	var $fpId;

	var $data = [];
	var $dataIncrease = [];
	var $dataDecrease = [];
	var $totals;
	var $groupByTypes = [];
	var $groupByDepsGroups = [];
	var $groupByDebsAccounts = [];
	var $groupBy = '';


	function init ()
	{
		$this->tableProperty = $this->app->table ('e10pro.property.property');
		$this->depsGroups = $this->app->cfgItem('e10pro.property.depGroups');
		//$this->propertyKinds = $tableProperty->columnInfoEnum ('propertyKind');

		$this->addParam ('fiscalYear');
		$this->addParam ('switch', 'groupBy', ['title' => 'Seskupit podle', 'switch' => $this->groupByEnum/*, 'radioBtn' => 1*/]);

		parent::init();

		if ($this->fiscalYear === 0)
			$this->fiscalYear = $this->reportParams ['fiscalYear']['value'];
		$this->periodBegin = $this->reportParams ['fiscalYear']['values'][$this->fiscalYear]['dateBegin'];
		$this->periodEnd = $this->reportParams ['fiscalYear']['values'][$this->fiscalYear]['dateEnd'];
		$this->fpId = 'E'.$this->fiscalYear;

		if ($this->groupBy === '')
			$this->groupBy = $this->reportParams ['groupBy']['value'];

		$this->setInfo('param', 'Období', $this->reportParams ['fiscalYear']['activeTitle']);
	}

	function loadData ()
	{
		$this->loadDataProperty();
		$this->loadDataDeferredTax();
		$this->loadDataIncrease();
		$this->loadDataDecrease();
	}

	function loadDataProperty ()
	{
		$this->totals = [
				'all' => ['acc' => 0.0, 'tax' => 0.0, 'taxUsed' => 0.0, 'diff' => 0.0, 'priceIn' => 0.0],
				'balance' => ['acc' => 0.0, 'tax' => 0.0, 'priceIn' => 0.0],
				'decreased' => ['acc' => 0.0, 'tax' => 0.0, 'diff' => 0.0, 'priceIn' => 0.0],
				'groups' => [], 'accounts' => []
		];

		$q [] = 'SELECT property.*, types.fullName as typeName, debsGroups.debsAccPropIdProperty as debsAccount';
		array_push ($q, ' FROM [e10pro_property_property] as property');
		array_push ($q, ' LEFT JOIN [e10pro_property_types] AS types ON property.propertyType = types.ndx');
		array_push ($q, ' LEFT JOIN [e10doc_debs_groups] as debsGroups ON property.debsGroup = debsGroups.ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND property.docState != %i', 9800);

		array_push ($q, ' AND property.dateStart <= %d', $this->periodEnd,
				' AND (property.dateEnd IS NULL OR property.dateEnd >= %d)', $this->periodBegin);

		array_push ($q, ' AND propertyCategory IN %in', $this->propertyCategories);

		array_push ($q, ' AND EXISTS (',
												'SELECT ndx FROM e10pro_property_deps AS deps WHERE property.ndx = deps.property ',
													'AND deps.depsPart = 0 AND deps.rowType = 1', ' AND deps.dateAccounting <= %d', $this->periodEnd,
												')'
		);

		array_push ($q, ' ORDER BY property.propertyId, property.fullName');

		// -- property list
		$pks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dg = $r['depreciationGroup'];

			if (!$r['debsAccount'])
				$r['debsAccount'] = '---';
			if (!isset($this->groupByTypes[$r['propertyType']]))
				$this->groupByTypes[$r['propertyType']] = ['name' => $r['typeName'], 'items' => [], 'totals' => ['tax' => 0.0, 'taxUsed' => 0.0, 'acc' => 0.0, 'diff' => 0.0, 'priceIn' => 0.0]];
			$this->groupByTypes[$r['propertyType']]['items'][] = $r['ndx'];

			if (!isset($this->groupByDepsGroups[$r['depreciationGroup']]))
				$this->groupByDepsGroups[$r['depreciationGroup']] = [
						'name' => $this->depsGroups[$r['depreciationGroup']]['fullName'],
						'order' => $this->depsGroups[$r['depreciationGroup']]['fullName'],
						'items' => [], 'totals' => ['tax' => 0.0, 'taxUsed' => 0.0, 'acc' => 0.0, 'diff' => 0.0, 'priceIn' => 0.0]
				];
			$this->groupByDepsGroups[$r['depreciationGroup']]['items'][] = $r['ndx'];

			if (!isset($this->groupByDebsAccounts[$r['debsAccount']]))
				$this->groupByDebsAccounts[$r['debsAccount']] = ['name' => $r['debsAccount'], 'items' => [], 'totals' => ['tax' => 0.0, 'taxUsed' => 0.0, 'acc' => 0.0, 'diff' => 0.0, 'priceIn' => 0.0]];
			$this->groupByDebsAccounts[$r['debsAccount']]['items'][] = $r['ndx'];
			//debsAccountIdProperty

			$de = new \e10pro\property\DepreciationsEngine ($this->app);
			$de->init();
			$de->depOverviewCntCols = 10;
			$de->setThisFiscalPeriod ($this->periodBegin, $this->periodEnd);
			$de->setProperty($r['ndx']);
			$de->createDepsPlan();
			$de->createInfo();
			$de->calcDeferredTax ();

			$item = [
				'ndx' => $r['ndx'],
				'propertyId' => ['text' => $r['propertyId'], 'docAction' => 'edit', 'table' => 'e10pro.property.property', 'pk' => $r['ndx']],
				'fullName' => $r['fullName'], 'dg' => $r['depreciationGroup'], 'debsGroup' => $r['debsGroup']
			];

			$item['taxDep'] = $de->depTotals['taxDep']['this'];
			$item['taxDepUsed'] = $de->depTotals['taxDep']['thisUsed'];
			$item['accDep'] = $de->depTotals['accDep']['this'];
			$item['diffDep'] = $de->depTotals['accDep']['this'] - $de->depTotals['taxDep']['this'];

			$item['valPastTax'] = 0.0;
			$item['valThisTax'] = 0.0;
			if (isset($de->depTotals['taxDep']['past']))
			{
				$item['valPastTax'] = $de->depTotals['taxDep']['past'];
				$item['valThisTax'] = $de->depTotals['taxDep']['past'];
			}
			if (isset($de->depTotals['taxDep']['this']))
				$item['valThisTax'] += $de->depTotals['taxDep']['this'];

			$item['valPastAcc'] = 0.0;
			$item['valThisAcc'] = 0.0;
			if (isset($de->depTotals['accDep']['past']))
			{
				$item['valPastAcc'] = $de->depTotals['accDep']['past'];
				$item['valThisAcc'] = $de->depTotals['accDep']['past'];
			}
			if (isset($de->depTotals['accDep']['this']))
				$item['valThisAcc'] += $de->depTotals['accDep']['this'];


			if (isset($de->balances[$this->fpId]['decreased']))
				$item['decreased'] = 1;

			if (isset($de->balances[$this->fpId]['tax']['balance']) || isset($de->balances[$this->fpId]['acc']['balance']) || isset($de->balances[$this->fpId]['dt']))
			{
				$item['taxBalance'] = $de->balances[$this->fpId]['tax']['balance'];
				$item['accBalance'] = $de->balances[$this->fpId]['acc']['balance'];
				$item['diffDt'] = $de->balances[$this->fpId]['diff'];
				$item['dt'] = $de->balances[$this->fpId]['dt'];

				$this->totals['balance']['acc'] += $item['accBalance'];
				$this->totals['balance']['tax'] += $item['taxBalance'];
			}

			if ($this->subReportId === 'cards1')
			{
				$item['depsOverview'] = $de->depsOverviewContent();
				$item['depsInfoTable'] = $de->info['depsInfoTable'];
				$item['depsInfoHeader'] = $de->info['depsInfoHeader'];
			}
			elseif ($this->subReportId === 'cards2')
			{
				$item['taxDeps'] = $de->taxDepsContent();
				$item['accDeps'] = $de->accDepsContent();
				$item['depsInfoTable'] = $de->info['depsInfoTable'];
				$item['depsInfoHeader'] = $de->info['depsInfoHeader'];
			}
			elseif ($this->subReportId === 'cards3')
			{
				$item['depsOverview'] = $de->depsOverviewContentVertical();
				$item['depsInfoTable'] = $de->info['depsInfoTable'];
				$item['depsInfoHeader'] = $de->info['depsInfoHeader'];
			}

			$this->totals['all']['tax'] += $de->depTotals['taxDep']['this'];
			$this->totals['all']['taxUsed'] += $de->depTotals['taxDep']['thisUsed'];
			$this->totals['all']['acc'] += $de->depTotals['accDep']['this'];
			$this->totals['all']['diff'] += $de->depTotals['accDep']['this'] - $de->depTotals['taxDep']['this'];

			$this->groupByTypes[$r['propertyType']]['totals']['tax'] += $de->depTotals['taxDep']['this'];
			$this->groupByTypes[$r['propertyType']]['totals']['taxUsed'] += $de->depTotals['taxDep']['thisUsed'];
			$this->groupByTypes[$r['propertyType']]['totals']['acc'] += $de->depTotals['accDep']['this'];
			$this->groupByTypes[$r['propertyType']]['totals']['diff'] += $de->depTotals['accDep']['this'] - $de->depTotals['taxDep']['this'];

			$this->groupByDepsGroups[$r['depreciationGroup']]['totals']['tax'] += $de->depTotals['taxDep']['this'];
			$this->groupByDepsGroups[$r['depreciationGroup']]['totals']['taxUsed'] += $de->depTotals['taxDep']['thisUsed'];
			$this->groupByDepsGroups[$r['depreciationGroup']]['totals']['acc'] += $de->depTotals['accDep']['this'];
			$this->groupByDepsGroups[$r['depreciationGroup']]['totals']['diff'] += $de->depTotals['accDep']['this'] - $de->depTotals['taxDep']['this'];

			$this->groupByDebsAccounts[$r['debsAccount']]['totals']['tax'] += $de->depTotals['taxDep']['this'];
			$this->groupByDebsAccounts[$r['debsAccount']]['totals']['taxUsed'] += $de->depTotals['taxDep']['thisUsed'];
			$this->groupByDebsAccounts[$r['debsAccount']]['totals']['acc'] += $de->depTotals['accDep']['this'];
			$this->groupByDebsAccounts[$r['debsAccount']]['totals']['diff'] += $de->depTotals['accDep']['this'] - $de->depTotals['taxDep']['this'];

			if (count($de->errors))
				$item['error'] = 1;

			$this->data[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		// -- inclusion
		$q = [];
		$q [] = 'SELECT * FROM [e10pro_property_deps] WHERE 1';
		array_push ($q, ' AND property IN %in', $pks);
		array_push ($q, ' AND depsPart = 0 AND rowType IN %in', [1, 2, 4]);
		array_push ($q, ' AND [docStateMain] < 4');
		array_push ($q, ' AND dateAccounting <= %d', $this->periodEnd);
		array_push ($q, ' ORDER BY dateAccounting');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$pndx = $r['property'];
			if (!isset($this->data[$pndx]['dateInclusion']))
				$this->data[$pndx]['dateInclusion'] = $r['dateAccounting'];
			if (!isset($this->data[$pndx]['priceIn']))
				$this->data[$pndx]['priceIn'] = $r['amount'];
			elseif ($r['rowType'] === 4)
				$this->data[$pndx]['priceIn'] -= $r['amount'];
			else
				$this->data[$pndx]['priceIn'] += $r['amount'];

			if (!isset($this->data[$pndx]['priceBegin']))
				$this->data[$pndx]['priceBegin'] = 0;
			if (!isset($this->data[$pndx]['priceIncrease']))
				$this->data[$pndx]['priceIncrease'] = 0;
			if (!isset($this->data[$pndx]['priceDecrease']))
				$this->data[$pndx]['priceDecrease'] = 0;

			if ($r['periodBegin'] < $this->periodBegin)
			{
				if ($r['rowType'] === 4)
					$this->data[$pndx]['priceBegin'] -= $r['amount'];
				else
					$this->data[$pndx]['priceBegin'] += $r['amount'];
			}
			else
			{
				if ($r['rowType'] === 4)
					$this->data[$pndx]['priceDecrease'] += $r['amount'];
				else
					$this->data[$pndx]['priceIncrease'] += $r['amount'];
			}

			$this->data[$pndx]['taxBalanceThis'] = $this->data[$pndx]['priceIn'] - $this->data[$pndx]['valThisTax'];
			$this->data[$pndx]['accBalanceThis'] = $this->data[$pndx]['priceIn'] - $this->data[$pndx]['valThisAcc'];
		}
	}

	function loadDataDeferredTax ()
	{
		//			$this->balances[$fpId]['tax']['balance'] = $taxBalance;

	}

	function loadDataIncrease ()
	{
		// -- long term
		$q [] = 'SELECT property.*';
		array_push ($q, ' FROM [e10pro_property_property] as property');
		array_push ($q, ' WHERE propertyCategory IN %in', [TableProperty::pcLongTermTangible, TableProperty::pcLongTermIntangible]);
		array_push ($q, ' AND docState != 9800');
		array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10pro_property_deps AS deps WHERE property.ndx = deps.property',
				' AND deps.depsPart = 0 AND deps.rowType IN %in', [1, 2],
				' AND deps.dateAccounting <= %d', $this->periodEnd, ' AND deps.dateAccounting >= %d', $this->periodBegin,
				' AND deps.[docStateMain] < 4',
				')');
		array_push ($q, ' ORDER BY property.propertyId, property.fullName');

		$pks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['propertyId' => $r['propertyId'], 'fullName' => $r['fullName'], 'dg' => $r['depreciationGroup'], 'debsGroup' => $r['debsGroup']];
			$this->dataIncrease['lt'][$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		// -- inclusion
		$q = [];
		$q [] = 'SELECT * FROM [e10pro_property_deps] WHERE 1';
		array_push ($q, ' AND property IN %in', $pks);
		array_push ($q, ' AND depsPart = 0 AND rowType IN %in', [1, 2]);
		array_push ($q, ' AND [docStateMain] < 4');
		//array_push ($q, ' AND dateAccounting <= %d', $this->periodEnd);
		array_push ($q, ' AND dateAccounting <= %d', $this->periodEnd, ' AND dateAccounting >= %d', $this->periodBegin);
		array_push ($q, ' ORDER BY dateAccounting');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$pndx = $r['property'];
			if (!isset($this->dataIncrease['lt'][$pndx]['dateInclusion']))
				$this->dataIncrease['lt'][$pndx]['dateInclusion'] = $r['dateAccounting'];
			if (!isset($this->dataIncrease['lt'][$pndx]['priceIn']))
				$this->dataIncrease['lt'][$pndx]['priceIn'] = $r['amount'];
			else
				$this->dataIncrease['lt'][$pndx]['priceIn'] += $r['amount'];
		}

		// -- short term
		$q = [];
		$q [] = 'SELECT property.*';
		array_push ($q, ' FROM [e10pro_property_property] as property');
		array_push ($q, ' WHERE propertyCategory IN %in', [TableProperty::pcShortTerm], ' AND propertyKind != %i', 1);
		array_push ($q, ' AND docState != 9800');
		array_push ($q, ' AND dateStart <= %d', $this->periodEnd, ' AND dateStart >= %d', $this->periodBegin);
		array_push ($q, ' ORDER BY property.propertyId, property.fullName');

		//$pks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['propertyId' => $r['propertyId'], 'fullName' => $r['fullName'], 'dateInclusion' => $r['dateStart'], 'priceIn' => $r['priceIn']];
			$this->dataIncrease['st'][$r['ndx']] = $item;
			//$pks[] = $r['ndx'];
		}
	}

	function loadDataDecrease ()
	{
		// -- long term
		$q [] = 'SELECT property.*';
		array_push ($q, ' FROM [e10pro_property_property] as property');
		array_push ($q, ' WHERE propertyCategory IN %in', [TableProperty::pcLongTermTangible, TableProperty::pcLongTermIntangible]);
		array_push ($q, ' AND docState != 9800');
		array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10pro_property_deps AS deps WHERE property.ndx = deps.property',
				' AND deps.depsPart = 0 AND deps.rowType = 120',
				' AND deps.dateAccounting <= %d', $this->periodEnd, ' AND deps.dateAccounting >= %d', $this->periodBegin,
				')');
		array_push ($q, ' ORDER BY property.propertyId, property.fullName');

		$pks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['propertyId' => $r['propertyId'], 'fullName' => $r['fullName'],
					'dg' => $r['depreciationGroup'], 'debsGroup' => $r['debsGroup'],
					'accBalance' => $this->data[$r['ndx']]['accBalance'],
					'taxBalance' => $this->data[$r['ndx']]['taxBalance']
			];
			$this->dataDecrease['lt'][$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		// -- inclusion
		$q = [];
		$q [] = 'SELECT * FROM [e10pro_property_deps] WHERE 1';
		array_push ($q, ' AND property IN %in', $pks);
		array_push ($q, ' AND depsPart = 0 AND rowType = 1');
		array_push ($q, ' AND [docStateMain] < 4');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$pndx = $r['property'];
			$this->dataDecrease['lt'][$pndx]['dateInclusion'] = $r['dateAccounting'];
			if (!isset($this->dataDecrease['lt'][$pndx]['priceIn']))
				$this->dataDecrease['lt'][$pndx]['priceIn'] = $r['amount'];
			else
				$this->dataDecrease['lt'][$pndx]['priceIn'] += $r['amount'];
		}

		// -- short term
		$q = [];
		$q [] = 'SELECT property.*';
		array_push ($q, ' FROM [e10pro_property_property] as property');
		array_push ($q, ' WHERE propertyCategory IN %in', [TableProperty::pcShortTerm], ' AND propertyKind != %i', 1);
		array_push ($q, ' AND docState != 9800');
		array_push ($q, ' AND dateEnd <= %d', $this->periodEnd, ' AND dateStart >= %d', $this->periodBegin);
		array_push ($q, ' ORDER BY property.propertyId, property.fullName');

		//$pks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['propertyId' => $r['propertyId'], 'fullName' => $r['fullName'], 'dateInclusion' => $r['dateStart'], 'priceIn' => $r['priceIn']];
			$this->dataDecrease['st'][$r['ndx']] = $item;
			//$pks[] = $r['ndx'];
		}
	}

	public function createContent()
	{
		parent::createContent();
		$this->loadData ();

		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum(); break;
			case 'balances': $this->createContent_Sum(); break;
			case 'taxUsed': $this->createContent_Sum(); break;
			case 'cards1': $this->createContent_Cards1(); break;
			case 'cards2': $this->createContent_Cards2(); break;
			case 'cards3': $this->createContent_Cards3(); break;
			case 'dt': $this->createContent_DeferredTax(); break;
			case 'increase': $this->createContent_Increase(); break;
			case 'decrease': $this->createContent_Decrease(); break;
		}
	}

	function createContent_Sum_AddPart_Title (&$destTable, $title)
	{
		$destTable[] = [
				'propertyId' => $title, 'accDep' => ' Úč. odpis', 'taxDep' => ' Daň. odpis', 'taxDepUsed' => ' Uplat. daň. odpis',
				'diffDep' => ' Rozdíl ÚO - DO',
				'priceIn' => 'Poř. cena',
				'valThisTax' => ' Daň. oprávky', 'taxBalanceThis' => ' Daň. ZC',
				'valThisAcc' => ' Úč. oprávky', 'accBalanceThis' => ' Úč. ZC',
				'diffAccTaxBalance' => ' ÚZC - DZC',
				'_options' => [
						'class' => 'subheader', 'beforeSeparator' => 'separator',
						'colSpan' => ['propertyId' => 4],
						'cellClasses' => [
								'priceIn' => 'e10-small', 'accDep' => 'e10-small', 'taxDep' => 'e10-small', 'taxDepUsed' => 'e10-small',
								'diffDep' => 'e10-small',
								'valThisTax' => 'e10-small', 'taxBalanceThis' => 'e10-small',
								'valThisAcc' => 'e10-small', 'accBalanceThis' => 'e10-small',
								'diffAccTaxBalance' => 'e10-small'
						]
				]
		];
	}

	function createContent_Sum_AddPart_Item (&$destTable, $propertyNdx, &$groupBy, $groupById)
	{
		if (!isset($groupBy[$groupById]['totals']['priceIn']))
			$groupBy[$groupById]['totals']['priceIn'] = 0.0;
		if (!isset($groupBy[$groupById]['totals']['priceBegin']))
			$groupBy[$groupById]['totals']['priceBegin'] = 0.0;
		if (!isset($groupBy[$groupById]['totals']['valThisTax']))
			$groupBy[$groupById]['totals']['valThisTax'] = 0.0;
		if (!isset($groupBy[$groupById]['totals']['valThisAcc']))
			$groupBy[$groupById]['totals']['valThisAcc'] = 0.0;
		if (!isset($groupBy[$groupById]['totals']['accBalanceThis']))
			$groupBy[$groupById]['totals']['accBalanceThis'] = 0.0;
		if (!isset($groupBy[$groupById]['totals']['diffAccTaxBalance']))
			$groupBy[$groupById]['totals']['diffAccTaxBalance'] = 0.0;
		if (!isset($groupBy[$groupById]['totals']['taxBalanceThis']))
			$groupBy[$groupById]['totals']['taxBalanceThis'] = 0.0;
		if (!isset($groupBy[$groupById]['totals']['priceIncrease']))
			$groupBy[$groupById]['totals']['priceIncrease'] = 0.0;
		if (!isset($groupBy[$groupById]['totals']['priceDecrease']))
			$groupBy[$groupById]['totals']['priceDecrease'] = 0.0;

		if (!isset($this->totals['all']['priceIn']))
			$this->totals['all']['priceIn'] = 0.0;
		if (!isset($this->totals['all']['priceBegin']))
			$this->totals['all']['priceBegin'] = 0.0;
		if (!isset($this->totals['all']['valThisTax']))
			$this->totals['all']['valThisTax'] = 0.0;
		if (!isset($this->totals['all']['valThisAcc']))
			$this->totals['all']['valThisAcc'] = 0.0;
		if (!isset($this->totals['all']['accBalanceThis']))
			$this->totals['all']['accBalanceThis'] = 0.0;
		if (!isset($this->totals['all']['diffAccTaxBalance']))
			$this->totals['all']['diffAccTaxBalance'] = 0.0;
		if (!isset($this->totals['all']['taxBalanceThis']))
			$this->totals['all']['taxBalanceThis'] = 0.0;
		if (!isset($this->totals['all']['priceIncrease']))
			$this->totals['all']['priceIncrease'] = 0.0;
		if (!isset($this->totals['all']['priceDecrease']))
			$this->totals['all']['priceDecrease'] = 0.0;

		if (!isset($this->totals['decreased']))
			$this->totals['decreased'] = [];
		if (!isset($this->totals['decreased']['priceIn']))
			$this->totals['decreased']['priceIn'] = 0.0;
		if (!isset($this->totals['decreased']['valThisTax']))
			$this->totals['decreased']['valThisTax'] = 0.0;
		if (!isset($this->totals['decreased']['valThisAcc']))
			$this->totals['decreased']['valThisAcc'] = 0.0;
		if (!isset($this->totals['decreased']['accBalanceThis']))
			$this->totals['decreased']['accBalanceThis'] = 0.0;
		if (!isset($this->totals['decreased']['diffAccTaxBalance']))
			$this->totals['decreased']['diffAccTaxBalance'] = 0.0;
		if (!isset($this->totals['decreased']['taxBalanceThis']))
			$this->totals['decreased']['taxBalanceThis'] = 0.0;
		if (!isset($this->totals['decreased']['taxBalanceThis']))
			$this->totals['decreased']['taxBalanceThis'] = 0.0;

		$p = $this->data[$propertyNdx];
		$groupBy[$groupById]['totals']['priceIn'] += $p['priceIn'];
		$groupBy[$groupById]['totals']['priceBegin'] += $p['priceBegin'];
		$groupBy[$groupById]['totals']['priceIncrease'] += $p['priceIncrease'];
		$groupBy[$groupById]['totals']['priceDecrease'] += $p['priceDecrease'];
		$this->totals['all']['priceIn'] += $p['priceIn'];
		$this->totals['all']['priceBegin'] += $p['priceBegin'];
		$this->totals['all']['priceIncrease'] += $p['priceIncrease'];
		$this->totals['all']['priceDecrease'] += $p['priceDecrease'];

		$this->totals['all']['valThisTax'] += $p['valThisTax'];
		if (isset($p['taxBalanceThis']))
			$this->totals['all']['taxBalanceThis'] += $p['taxBalanceThis'];
		if (isset($p['valThisAcc']))
			$this->totals['all']['valThisAcc'] += $p['valThisAcc'];
		$this->totals['all']['accBalanceThis'] += $p['accBalanceThis'];
		$this->totals['all']['diffAccTaxBalance'] += $p['accBalanceThis'] - $p['taxBalanceThis'];

		if (isset($p['valThisTax']))
			$groupBy[$groupById]['totals']['valThisTax'] += $p['valThisTax'];
		if (isset($p['taxBalanceThis']))
			$groupBy[$groupById]['totals']['taxBalanceThis'] += $p['taxBalanceThis'];
		if (isset($p['valThisAcc']))
			$groupBy[$groupById]['totals']['valThisAcc'] += $p['valThisAcc'];
		$groupBy[$groupById]['totals']['accBalanceThis'] += $p['accBalanceThis'];
		$groupBy[$groupById]['totals']['diffAccTaxBalance'] += $p['accBalanceThis'] - $p['taxBalanceThis'];


		$newItem = [
				'propertyId' => $p['propertyId'], 'fullName' => $p['fullName'], 'dateInclusion' => $p['dateInclusion'],
				'priceIn' => $p['priceIn'], 'taxDep' => $p['taxDep'], 'taxDepUsed' => $p['taxDepUsed'], 'accDep' => $p['accDep'], 'diffDep' => $p['diffDep'],
				'priceBegin' => $p['priceBegin'], 'priceIncrease' => $p['priceIncrease'], 'priceDecrease' => $p['priceDecrease'],
				'dg' => $this->depsGroups[$p['dg']]['id'],
				'valThisTax' => $p['valThisTax'], 'taxBalanceThis' => $p['taxBalanceThis'],
				'valThisAcc' => $p['valThisAcc'], 'accBalanceThis' => $p['accBalanceThis'],
				'diffAccTaxBalance' => $p['accBalanceThis'] - $p['taxBalanceThis']
		];

		if (isset($this->data[$propertyNdx]['decreased']))
		{
			if (!isset($groupBy[$groupById]['decreased']))
				$groupBy[$groupById]['decreased'] = ['priceIn' => 0.0, 'valThisTax' => 0.0, 'taxBalanceThis' => 0.0,'valThisAcc' => 0.0,'accBalanceThis' => 0.0,'diffAccTaxBalance' => 0.0,];

			$newItem['_options']['class'] = 'e10-row-minus';
			$this->totals['decreased']['priceIn'] += $p['priceIn'];

			$this->totals['decreased']['valThisTax'] += $p['valThisTax'];
			$this->totals['decreased']['taxBalanceThis'] += $p['taxBalanceThis'];
			$this->totals['decreased']['valThisAcc'] += $p['valThisAcc'];
			$this->totals['decreased']['accBalanceThis'] += $p['accBalanceThis'];
			$this->totals['decreased']['diffAccTaxBalance'] += $p['accBalanceThis'] - $p['taxBalanceThis'];

			$groupBy[$groupById]['decreased']['priceIn'] += $p['priceIn'];
			$groupBy[$groupById]['decreased']['valThisTax'] += $p['valThisTax'];
			$groupBy[$groupById]['decreased']['taxBalanceThis'] += $p['taxBalanceThis'];
			$groupBy[$groupById]['decreased']['valThisAcc'] += $p['valThisAcc'];
			$groupBy[$groupById]['decreased']['accBalanceThis'] += $p['accBalanceThis'];
			$groupBy[$groupById]['decreased']['diffAccTaxBalance'] += $p['accBalanceThis'] - $p['taxBalanceThis'];
		}

		if (isset($p['error']))
		{
			$newItem['_options']['cellClasses']['#'] = 'e10-error e10-bold';
			$this->setInfo('note', 'err'.count($destTable), 'Majetek '.$newItem['propertyId']['text'].' obsahuje chyby v odpisech');
			$this->setInfo('param', 'Upozornění', 'Některé karty majetku obsahují chyby');
		}

		$destTable[] = $newItem;
	}

	function createContent_Sum_AddPart_Total (&$destTable, &$groupBy, $groupById)
	{
		$total = [
				'priceIn' => $groupBy[$groupById]['totals']['priceIn'],
				'priceBegin' => $groupBy[$groupById]['totals']['priceBegin'],
				'priceIncrease' => $groupBy[$groupById]['totals']['priceIncrease'],
				'priceDecrease' => $groupBy[$groupById]['totals']['priceDecrease'],
				'taxDep' => $groupBy[$groupById]['totals']['tax'],
				'taxDepUsed' => $groupBy[$groupById]['totals']['taxUsed'],
				'accDep' => $groupBy[$groupById]['totals']['acc'],
				'diffDep' => $groupBy[$groupById]['totals']['diff'],
				'valThisTax' => $groupBy[$groupById]['totals']['valThisTax'],
				'taxBalanceThis' => $groupBy[$groupById]['totals']['taxBalanceThis'],
				'valThisAcc' => $groupBy[$groupById]['totals']['valThisAcc'],
				'accBalanceThis' => $groupBy[$groupById]['totals']['accBalanceThis'],
				'diffAccTaxBalance' => $groupBy[$groupById]['totals']['diffAccTaxBalance'],
				'_options' => ['class' => 'sumtotal'],
		];
		$destTable[] = $total;

		if (isset($groupBy[$groupById]['decreased']))
		{
			$totalDecreased = [
					'propertyId' => 'Vyřazeno',
					'priceIn' => $groupBy[$groupById]['decreased']['priceIn'],

					'valThisTax' => $groupBy[$groupById]['decreased']['valThisTax'],
					'taxBalanceThis' => $groupBy[$groupById]['decreased']['taxBalanceThis'],
					'valThisAcc' => $groupBy[$groupById]['decreased']['valThisAcc'],
					'accBalanceThis' => $groupBy[$groupById]['decreased']['accBalanceThis'],
					'diffAccTaxBalance' => $groupBy[$groupById]['decreased']['diffAccTaxBalance'],

					'_options' => ['class' => 'sumtotal e10-row-minus', 'ccbeforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]],
			];
			$destTable[] = $totalDecreased;
			$totalBalance = [
					'propertyId' => 'Zůstatek',
					'priceIn' => $groupBy[$groupById]['totals']['priceIn'] - $groupBy[$groupById]['decreased']['priceIn'],

					'valThisTax' => $groupBy[$groupById]['totals']['valThisTax'] - $groupBy[$groupById]['decreased']['valThisTax'],
					'taxBalanceThis' => $groupBy[$groupById]['totals']['taxBalanceThis'] - $groupBy[$groupById]['decreased']['taxBalanceThis'],
					'valThisAcc' => $groupBy[$groupById]['totals']['valThisAcc'] - $groupBy[$groupById]['decreased']['valThisAcc'],
					'accBalanceThis' => $groupBy[$groupById]['totals']['accBalanceThis'] - $groupBy[$groupById]['decreased']['accBalanceThis'],
					'diffAccTaxBalance' => $groupBy[$groupById]['totals']['diffAccTaxBalance'] - $groupBy[$groupById]['decreased']['diffAccTaxBalance'],
					'_options' => ['class' => 'sumtotal e10-row-plus', 'ccbeforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]],
			];
			$destTable[] = $totalBalance;

			$this->totals['balance']['priceIn'] -= $this->totals['decreased']['priceIn'];
		}
	}

	function createContent_Sum ()
	{
		$t = [];

		if ($this->groupBy === '-')
		{
			$this->setInfo('title', 'Odpisy majetku');
			foreach ($this->data as $propertyNdx => $p)
			{
				$none = [];
				$this->createContent_Sum_AddPart_Item ($t, $propertyNdx, $none, 0);
			}
		}
		elseif ($this->groupBy === 'types')
		{
			$this->setInfo('title', 'Odpisy majetku podle typu');
			foreach (\E10\sortByOneKey($this->groupByTypes, 'name', TRUE) as $typeNdx => $type)
			{
				$this->createContent_Sum_AddPart_Title ($t, $type['name']);
				foreach ($type['items'] as $propertyNdx)
					$this->createContent_Sum_AddPart_Item ($t, $propertyNdx, $this->groupByTypes, $typeNdx);
				$this->createContent_Sum_AddPart_Total ($t, $this->groupByTypes, $typeNdx);
			}
		}
		elseif ($this->groupBy === 'depsGroups')
		{
			$this->setInfo('title', 'Odpisy majetku podle odpisových skupin');
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
			$this->setInfo('title', 'Odpisy majetku podle účtů');
			foreach (\E10\sortByOneKey($this->groupByDebsAccounts, 'name', TRUE) as $accountId => $account)
			{
				$this->createContent_Sum_AddPart_Title ($t, $account['name']);
				foreach ($account['items'] as $propertyNdx)
					$this->createContent_Sum_AddPart_Item ($t, $propertyNdx, $this->groupByDebsAccounts, $accountId);
				$this->createContent_Sum_AddPart_Total ($t, $this->groupByDebsAccounts, $accountId);
			}
		}

		$total = [
				'propertyId' => 'CELKEM', 'priceIn' => $this->totals['all']['priceIn'],
				'taxDep' => $this->totals['all']['tax'], 'taxDepUsed' => $this->totals['all']['taxUsed'], 'accDep' => $this->totals['all']['acc'], 'diffDep' => $this->totals['all']['diff'],

				'valThisTax' => $this->totals['all']['valThisTax'], 'taxBalanceThis' => $this->totals['all']['taxBalanceThis'],
				'valThisAcc' => $this->totals['all']['valThisAcc'], 'accBalanceThis' => $this->totals['all']['accBalanceThis'],
				'diffAccTaxBalance' => $this->totals['all']['diffAccTaxBalance'],

				'_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]],
		];

		$t[] = $total;

		$this->totals['balance']['priceIn'] = $this->totals['all']['priceIn'];

		if (isset($this->totals['decreased']['priceIn']) && $this->totals['decreased']['priceIn'])
		{
			$totalDecreased = [
					'propertyId' => 'Vyřazeno',
					'priceIn' => $this->totals['decreased']['priceIn'],

					'valThisTax' => $this->totals['decreased']['valThisTax'],
					'taxBalanceThis' => $this->totals['decreased']['taxBalanceThis'],
					'valThisAcc' => $this->totals['decreased']['valThisAcc'],
					'accBalanceThis' => $this->totals['decreased']['accBalanceThis'],
					'diffAccTaxBalance' => $this->totals['decreased']['diffAccTaxBalance'],

					'_options' => ['class' => 'sumtotal e10-row-minus', 'ccbeforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]],
			];
			$t[] = $totalDecreased;
			$totalBalance = [
					'propertyId' => 'Zůstatek',
					'priceIn' => $this->totals['all']['priceIn'] - $this->totals['decreased']['priceIn'],
					'valThisTax' => $this->totals['all']['valThisTax'] - $this->totals['decreased']['valThisTax'],
					'taxBalanceThis' => $this->totals['all']['taxBalanceThis'] - $this->totals['decreased']['taxBalanceThis'],
					'valThisAcc' => $this->totals['all']['valThisAcc'] - $this->totals['decreased']['valThisAcc'],
					'accBalanceThis' => $this->totals['all']['accBalanceThis'] - $this->totals['decreased']['accBalanceThis'],
					'diffAccTaxBalance' => $this->totals['all']['diffAccTaxBalance'] - $this->totals['decreased']['diffAccTaxBalance'],
					'_options' => ['class' => 'sumtotal e10-row-plus', 'ccbeforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]],
			];
			$t[] = $totalBalance;

			$this->totals['balance']['priceIn'] -= $this->totals['decreased']['priceIn'];
		}


		if ($this->subReportId === 'balances')
		{
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
		if ($this->subReportId === 'taxUsed')
		{
			$h = [
				'#' => '#', 'propertyId' => 'InvČ', 'fullName' => 'Název', 'dg' => ' Sk.',
				'dateInclusion' => 'Zařazeno', 'priceIn' => ' Poř. cena',
				'taxDep' => ' Daň. odpis', 'taxDepUsed' => ' Uplat. daň. odpis',
			];
		}
		else
		{
			$h = [
					'#' => '#', 'propertyId' => 'InvČ', 'fullName' => 'Název', 'dg' => ' Sk.',
					'dateInclusion' => 'Zařazeno', 'priceIn' => ' Poř. cena',
					'accDep' => ' Úč. odpis', 'taxDep' => ' Daň. odpis', 'diffDep' => ' Rozdíl ÚO - DO',
			];
		}
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);


		$this->setInfo('icon', 'report/depreciations');
		$this->paperOrientation = 'landscape';
	}

	function createContent_Cards1 ()
	{
		foreach ($this->data as $propertyNdx => $p)
		{
			$this->setInfo('icon', 'icon-university');
			$this->setInfo('title', $p['propertyId']['text']);
			$this->setInfo('param', 'Karta majetku', $p['fullName']);
			$this->setInfo('worksheetTitle', $p['propertyId']['text']);

			$this->addContent(['type' => 'reportHeader', 'reportHeader' => $this->info]);

			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<table class='fullWidth'><tr><td style='width: 60%;'>"]);
			$this->addContent(['type' => 'table', 'table' => $p['depsInfoTable'], 'header' => $p['depsInfoHeader']]);
			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</td><td></td></tr></table><br/>"]);

			$this->addContent($p['depsOverview'][0]);

			$pb = "<div class='pageBreakAfter'></div>";
			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $pb]);
		}

		$this->paperOrientation = 'landscape';
	}

	function createContent_Cards2 ()
	{
		foreach ($this->data as $propertyNdx => $p)
		{
			$this->setInfo('icon', 'icon-university');
			$this->setInfo('title', $p['propertyId']['text']);
			$this->setInfo('param', 'Karta majetku', $p['fullName']);
			$this->setInfo('worksheetTitle', $p['propertyId']['text']);

			$this->addContent(['type' => 'reportHeader', 'reportHeader' => $this->info]);

			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<table class='fullWidth'><tr><td style='width: 75%;'>"]);
			$this->addContent(['type' => 'table', 'table' => $p['depsInfoTable'], 'header' => $p['depsInfoHeader']]);
			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</td><td></td></tr></table>"]);

			$this->addContent(['type' => 'table', 'table' => $p['taxDeps']['table'], 'header' => $p['taxDeps']['header'], 'title' => 'Daňové odpisy', 'params' => ['tableClass' => 'e10-print-small']]);
			$this->addContent(['type' => 'table', 'table' => $p['accDeps']['table'], 'header' => $p['accDeps']['header'], 'title' => 'Účetní odpisy', 'params' => ['tableClass' => 'e10-print-small']]);

			$pb = "<div class='pageBreakAfter'></div>";
			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $pb]);
		}

		$this->paperOrientation = 'landscape';
	}

	function createContent_Cards3 ()
	{
		foreach ($this->data as $propertyNdx => $p)
		{
			$this->setInfo('icon', 'icon-university');
			$this->setInfo('title', $p['propertyId']['text']);
			$this->setInfo('param', 'Karta majetku', $p['fullName']);
			$this->setInfo('worksheetTitle', $p['propertyId']['text']);

			$this->addContent(['type' => 'reportHeader', 'reportHeader' => $this->info]);

			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<table class='fullWidth'><tr><td style='width: 75%;'>"]);
			$this->addContent(['type' => 'table', 'table' => $p['depsInfoTable'], 'header' => $p['depsInfoHeader']]);
			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</td><td></td></tr></table>"]);

			$this->addContent(['type' => 'table', 'table' => $p['depsOverview']['table'], 'header' => $p['depsOverview']['header'], 'title' => 'Odpisy', 'params' => ['tableClass' => 'e10-print-small']]);

			$pb = "<div class='pageBreakAfter'></div>";
			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $pb]);
		}

		$this->paperOrientation = 'landscape';
	}

	function createContent_DeferredTax ()
	{
		$t = [];
		$sums = [];

		if ($this->groupBy === '-')
		{
			$this->setInfo('title', 'Odložená daň');
			foreach ($this->data as $propertyNdx => $p)
			{
				$this->createContent_DeferredTax_AddItem($t, $sums, $p, 0);
			}
		}
		elseif ($this->groupBy === 'types')
		{
			$this->setInfo('title', 'Odložená daň podle typu');
			foreach (\E10\sortByOneKey($this->groupByTypes, 'name', TRUE) as $typeNdx => $type)
			{
				$this->createContent_DeferredTax_AddTitle ($t, $sums, $typeNdx, $type['name']);
				foreach ($type['items'] as $propertyNdx)
					$this->createContent_DeferredTax_AddItem($t, $sums, $this->data[$propertyNdx], $typeNdx);
				$this->createContent_DeferredTax_AddTotal ($t, $sums, $typeNdx);
			}
		}
		elseif ($this->groupBy === 'depsGroups')
		{
			$this->setInfo('title', 'Odložená daň podle odpisových skupin');
			foreach (\E10\sortByOneKey($this->groupByDepsGroups, 'order', TRUE) as $depGroupId => $depGroup)
			{
				$this->createContent_DeferredTax_AddTitle ($t, $sums, $depGroupId, $depGroup['name']);
				foreach ($depGroup['items'] as $propertyNdx)
					$this->createContent_DeferredTax_AddItem($t, $sums, $this->data[$propertyNdx], $depGroupId);
				$this->createContent_DeferredTax_AddTotal ($t, $sums, $depGroupId);
			}
		}
		elseif ($this->groupBy === 'debsAccounts')
		{
			$this->setInfo('title', 'Odložená daň podle účtů');
			foreach (\E10\sortByOneKey($this->groupByDebsAccounts, 'name', TRUE) as $accountId => $account)
			{
				$this->createContent_DeferredTax_AddTitle ($t, $sums, $accountId, $account['name']);
				foreach ($account['items'] as $propertyNdx)
					$this->createContent_DeferredTax_AddItem($t, $sums, $this->data[$propertyNdx], $accountId);
				$this->createContent_DeferredTax_AddTotal ($t, $sums, $accountId);
			}
		}

		$this->createContent_DeferredTax_AddTotal ($t, $sums, 'TOTAL');

		$h = [
				'#' => '#', 'propertyId' => 'InvČ', 'fullName' => 'Název', 'dg' => ' Sk.',
				'dateInclusion' => 'Zařazeno', 'priceIn' => ' Poř. cena',
				'accBalance' => ' Úč. ZC', 'taxBalance' => ' Daň. ZC', 'diffDt' => ' Rozdíl ÚZC - DZC',
				'dt' => ' Odložená daň'
		];
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);


		$this->setInfo('icon', 'icon-shield');
		$this->paperOrientation = 'landscape';
	}

	function createContent_DeferredTax_AddItem (&$table, &$sums, $p, $groupId)
	{
		if (isset($p['decreased']) && $p['accDep'] == 0.0 && $p['taxDep'] == 0.0)
			return;

		$newItem = [
				'propertyId' => $p['propertyId'], 'fullName' => $p['fullName'], 'dateInclusion' => $p['dateInclusion'],
				'priceIn' => $p['priceIn'],
				'taxBalance' => $p['taxBalanceThis'], 'accBalance' => $p['accBalanceThis'], 'diffDt' => $p['diffDt'], 'dt' => $p['dt'],
				'dg' => $this->depsGroups[$p['dg']]['id'],
		];
		$table[] = $newItem;

		$sums[$groupId]['priceIn'] += $p['priceIn'];
		$sums[$groupId]['taxBalance'] += $p['taxBalanceThis'];
		$sums[$groupId]['accBalance'] += $p['accBalanceThis'];
		$sums[$groupId]['diffDt'] += $p['diffDt'];
		$sums[$groupId]['dt'] += $p['dt'];

		$sums['TOTAL']['priceIn'] += $p['priceIn'];
		$sums['TOTAL']['taxBalance'] += $p['taxBalanceThis'];
		$sums['TOTAL']['accBalance'] += $p['accBalanceThis'];
		$sums['TOTAL']['diffDt'] += $p['diffDt'];
		$sums['TOTAL']['dt'] += $p['dt'];
	}

	function createContent_DeferredTax_AddTitle (&$destTable, &$sums, $groupId, $title)
	{
		$sums[$groupId] = ['priceIn' => 0.0, 'taxBalance' => 0.0, 'accBalance' => 0.0, 'diffDt' => 0.0, 'dt' => 0.0];
		if (!isset($sums['TOTAL']))
			$sums['TOTAL'] = ['priceIn' => 0.0, 'taxBalance' => 0.0, 'accBalance' => 0.0, 'diffDt' => 0.0, 'dt' => 0.0];

		$destTable[] = [
				'propertyId' => $title,

				'dg' => ' Sk.',
				'dateInclusion' => 'Zařazeno', 'priceIn' => ' Poř. cena',
				'accBalance' => ' Úč. ZC', 'taxBalance' => ' Daň. ZC', 'diffDt' => ' Rozdíl ÚZC - DZC',
				'dt' => ' Odložená daň',
				'_options' => [
						'class' => 'subheader', 'beforeSeparator' => 'separator',
						'colSpan' => ['propertyId' => 2],
						'cellClasses' => [
								'dg' => 'e10-small', 'dateInclusion' => 'e10-small', 'priceIn' => 'e10-small', 'accBalance' => 'e10-small',
								'taxBalance' => 'e10-small', 'diffDt' => 'e10-small',
								'dt' => 'e10-small'
						]
				]
		];
	}

	function createContent_DeferredTax_AddTotal (&$destTable, &$sums, $groupId)
	{
		$newItem = $sums[$groupId];
		if ($groupId === 'TOTAL')
			$newItem['_options'] = ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]];
		else
			$newItem['_options'] = ['class' => 'sumtotal', 'ccbeforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]];
		$destTable[] = $newItem;
	}

	function createContent_Increase ()
	{
		// -- long term
		$t = [];

		$totals = ['lt' => ['priceIn' => 0.0], 'st' => ['priceIn' => 0.0], 'all' => ['priceIn' => 0.0]];
		$totals['lt']['_options'] = ['class' => 'subtotal', 'afterSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]];
		$totals['st']['_options'] = ['class' => 'subtotal', 'colSpan' => ['propertyId' => 4]];
		$totals['all']['_options'] = ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]];

		if (isset($this->dataIncrease['lt']) && count($this->dataIncrease['lt']))
		{
			$t[] = ['#' => 'Dlouhodobý majetek', '_options' => ['class' => 'subheader', 'colSpan' => ['#' => 6], 'cellClasses' => ['#' => 'e10-test']]];
			foreach ($this->dataIncrease['lt'] as $p)
			{
				$newItem = [
						'propertyId' => $p['propertyId'], 'fullName' => $p['fullName'], 'dateInclusion' => $p['dateInclusion'],
						'priceIn' => $p['priceIn'],
						'dg' => $this->depsGroups[$p['dg']]['id'],
				];
				$t[] = $newItem;
				$totals['lt']['priceIn'] += $p['priceIn'];
			}
			$t[] = $totals['lt'];
		}

		// -- short term
		if (isset($this->dataIncrease['st']) && count($this->dataIncrease['st']))
		{
			$t[] = ['#' => 'Krátkodobý majetek', '_options' => ['class' => 'subheader', 'colSpan' => ['#' => 6], 'cellClasses' => ['#' => 'e10-test']]];
			foreach ($this->dataIncrease['st'] as $p)
			{
				$newItem = [
						'propertyId' => $p['propertyId'], 'fullName' => $p['fullName'], 'dateInclusion' => $p['dateInclusion'],
						'priceIn' => $p['priceIn'],
				];
				$t[] = $newItem;
				$totals['st']['priceIn'] += $p['priceIn'];
			}
			$t[] = $totals['st'];
		}

		if (isset($this->dataIncrease['lt']) && count($this->dataIncrease['lt']) && isset($this->dataIncrease['st']) && count($this->dataIncrease['st']))
		{
			$totals['all']['priceIn'] = $totals['lt']['priceIn'] + $totals['st']['priceIn'];
			$totals['all']['propertyId'] = 'CELKEM:';
			$t[] = $totals['all'];
		}


		$this->setInfo('icon', 'icon-chevron-circle-up');
		$this->setInfo('title', 'Přírustky majetku');
		$this->setInfo('param', 'Účetní období', $this->reportParams ['fiscalYear']['activeTitle']);


		$h = [
				'#' => '#', 'propertyId' => 'InvČ', 'fullName' => 'Název', 'dg' => ' Sk.',
				'dateInclusion' => 'Zařazeno', 'priceIn' => ' Přírustek poř. ceny',
		];
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);
	}

	function createContent_Decrease ()
	{
		// -- long term
		$t = [];

		$totals = ['lt' => ['priceIn' => 0.0], 'st' => ['priceIn' => 0.0], 'all' => ['priceIn' => 0.0]];
		$totals['lt']['_options'] = ['class' => 'subtotal', 'afterSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]];
		$totals['st']['_options'] = ['class' => 'subtotal', 'colSpan' => ['propertyId' => 4]];
		$totals['all']['_options'] = ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['propertyId' => 4]];

		if (isset($this->dataDecrease['lt']) && count($this->dataDecrease['lt']))
		{
			$t[] = ['#' => 'Dlouhodobý majetek', '_options' => ['class' => 'subheader', 'colSpan' => ['#' => 6], 'cellClasses' => ['#' => 'e10-test']]];
			foreach ($this->dataDecrease['lt'] as $p)
			{
				$newItem = [
						'propertyId' => $p['propertyId'], 'fullName' => $p['fullName'], 'dateInclusion' => $p['dateInclusion'],
						'priceIn' => $p['priceIn'],
						'dg' => $this->depsGroups[$p['dg']]['id'],
				];
				$t[] = $newItem;
				$totals['lt']['priceIn'] += $p['priceIn'];
			}
			$t[] = $totals['lt'];
		}

		// -- short term
		if (isset($this->dataDecrease['st']) && count($this->dataDecrease['st']))
		{
			$t[] = ['#' => 'Krátkodobý majetek', '_options' => ['class' => 'subheader', 'colSpan' => ['#' => 6], 'cellClasses' => ['#' => 'e10-test']]];
			foreach ($this->dataDecrease['st'] as $p)
			{
				$newItem = [
						'propertyId' => $p['propertyId'], 'fullName' => $p['fullName'], 'dateInclusion' => $p['dateInclusion'],
						'priceIn' => $p['priceIn'],
				];
				$t[] = $newItem;
				$totals['st']['priceIn'] += $p['priceIn'];
			}
			$t[] = $totals['st'];
		}

		if (isset($this->dataDecrease['lt']) && count($this->dataDecrease['lt']) && isset($this->dataDecrease['st']) && count($this->dataDecrease['st']))
		{
			$totals['all']['priceIn'] = $totals['lt']['priceIn'] + $totals['st']['priceIn'];
			$totals['all']['propertyId'] = 'CELKEM:';
			$t[] = $totals['all'];
		}

		$this->setInfo('icon', 'icon-chevron-circle-down');
		$this->setInfo('title', 'Úbytky majetku');
		$this->setInfo('param', 'Účetní období', $this->reportParams ['fiscalYear']['activeTitle']);


		$h = [
				'#' => '#', 'propertyId' => 'InvČ', 'fullName' => 'Název', 'dg' => ' Sk.',
				'dateInclusion' => 'Zařazeno', 'priceIn' => ' Poř. cena',
		];
		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);
	}


	public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icon' => 'detailReportSum', 'title' => 'Sumárně'];
		$d[] = ['id' => 'balances', 'icon' => 'detailReportAccountBalances', 'title' => 'Zůstatky'];
		$d[] = ['id' => 'cards1', 'icon' => 'detailReportCards1', 'title' => 'Karty 1'];
		$d[] = ['id' => 'cards2', 'icon' => 'detailReportCards2', 'title' => 'Karty 2'];
		$d[] = ['id' => 'cards3', 'icon' => 'detailReportCards3', 'title' => 'Karty 3'];
		$d[] = ['id' => 'dt', 'icon' => 'detailReportPostponedTax', 'title' => 'Odložená daň'];
		$d[] = ['id' => 'taxUsed', 'icon' => 'detailReportTaxDepreciations', 'title' => 'Daňové odpisy'];
		$d[] = ['id' => 'increase', 'icon' => 'detailReportIncrements', 'title' => 'Přírustky'];
		$d[] = ['id' => 'decrease', 'icon' => 'detailReportDepletions', 'title' => 'Úbytky'];
		return $d;
	}

	public function createReportContentHeader ($contentPart)
	{
		if ($this->subReportId === 'cards1' || $this->subReportId === 'cards2' || $this->subReportId === 'cards3')
			return '';

		return parent::createReportContentHeader($contentPart);
	}
}

<?php

namespace e10pro\property;

use e10\Utility, e10\utils, e10doc\core\e10utils;

/**
 * Class DeprecationsEngine
 * @package e10pro\property
 */
class DepreciationsEngine extends Utility
{
	var $propertyNdx = 0;
	var $tableProperty;
	var $tableDeps;
	var $dataProperty;
	var $dg;
	var $depGroups;
	var $depTypes;
	var $accDepTypes;
	var $amortizationPeriod;
	var $yearCounter = 0;
	var $monthCounter = 0;
	var $monthlyDeprecation;

	var $taxDepsPlan;
	var $accDepsPlan;
	var $nonDepsPlan;
	var $depOverview;
	var $balances;
	var $depOverviewCntCols = 6;
	var $depTotals;
	var $dateBegin = NULL;
	var $dateEnd = NULL;
	var $thisFPBegin = NULL;
	var $thisFPEnd = NULL;

	var $lastDepsDate = NULL;

	var $fullOverviewLabels = FALSE;

	var $info;
	var $errors = [];

	public function init ()
	{
		$this->depGroups = $this->app->cfgItem ('e10pro.property.depGroups');
		$this->depTypes = $this->app->cfgItem ('e10pro.property.depTypes');
		$this->accDepTypes = $this->app->cfgItem ('e10pro.property.accDepTypes');
		$this->amortizationPeriod = $this->app->cfgItem ('e10pro.property.amortizationPeriod');
		$this->tableProperty = $this->app->table ('e10pro.property.property');
		$this->tableDeps = $this->app->table ('e10pro.property.deps');
	}

	public function setProperty ($propertyNdx)
	{
		$this->propertyNdx = $propertyNdx;
		$this->dataProperty = $this->tableProperty->loadItem ($this->propertyNdx);

		$this->dg = $this->depGroups [$this->dataProperty['depreciationGroup']];
		$this->monthlyDeprecation = 0;

		$this->depOverview = [];
		$this->depTotals = [];
		$this->balances = [];
		$this->dateBegin = NULL;
		$this->dateEnd = NULL;

		$this->fullOverviewLabels = FALSE;
		$firstMonth = $this->app()->cfgItem ('options.core.firstFiscalYearMonth', 0);
		//if ($firstMonth != 1)
		//	$this->fullOverviewLabels = TRUE;
	}

	public function setThisFiscalPeriod ($periodBegin, $periodEnd)
	{
		if (!$periodBegin)
		{
			$fp = e10utils::todayFiscalYear($this->app(), utils::today(), TRUE);
			$this->thisFPBegin = utils::createDateTime($fp['begin']);
			$this->thisFPEnd = utils::createDateTime($fp['end']);
		}
		else
		{
			$this->thisFPBegin = utils::createDateTime($periodBegin);
			$this->thisFPEnd = utils::createDateTime($periodEnd);
		}
	}

	function checkRecord ($r, &$item)
	{
		$msg = FALSE;

		if ($r === NULL)
		{
			if ($this->lastDepsDate && $this->lastDepsDate > utils::createDateTime($item['dateAccountingDate']))
			{
				$msg = ['text' => 'Nepotvrzený odpis před poslední změnou v odpisech'];
				$item['_options']['cellClasses']['icon'] .= ' e10-error';
				$item['_options']['cellClasses']['dateAccounting'] = 'e10-warning3';
				$this->errors[] = $msg;
				$item['error'] = 1;
			}
			return;
		}

		if (!utils::dateIsBlank($r['dateAccounting']))
		{
			$this->lastDepsDate = $r['dateAccounting'];
		}


		if (utils::dateIsBlank($r['dateAccounting']))
		{
			$msg = ['text' => 'Není zadáno účetní datum'];
		}
		else
		if ($r['rowType'] == 99)
		{
			if (utils::dateIsBlank($r['periodBegin']))
			{
				$msg = ['text' => 'Není zadáno datum začátku období'];
			}
			elseif (utils::dateIsBlank($r['periodEnd']))
			{
				$msg = ['text' => 'Není zadáno datum konce období'];
			}
			elseif (e10utils::todayFiscalYear($this->app(), $r['dateAccounting']) != e10utils::todayFiscalYear($this->app(), $r['periodEnd']))
			{
				$msg = ['text' => 'Účetní datum je v jiném fiskálním období než koncové datum'];
			}
			elseif (utils::createDateTime($r['dateAccounting']) < utils::createDateTime($r['periodBegin']))
			{
				$msg = ['text' => 'Účetní datum je PŘED obdobím odpisu'];
			}
		}

		if ($msg !== FALSE)
		{
			$item['_options']['cellClasses']['icon'] .= ' e10-error';
			$item['_options']['cellClasses']['dateAccounting'] = 'e10-warning3';
			$this->errors[] = $msg;
			$item['error'] = 1;
		}
	}

	function createTaxDepsPlan($planOnly = TRUE)
	{
		$this->yearCounter = 0;
		$this->monthCounter = 0;
		if (!$this->thisFPBegin)
			$this->setThisFiscalPeriod (NULL, NULL);

		$this->depTotals['taxDep'] = [
				'all' => 0.0, 'request' => 0.0, 'past' => 0.0, 'this' => 0.0, 'thisUsed' => 0.0, 'calc' => 0.0,
				'pastLastDate' => NULL, 'thisLastDate' => NULL];
		$this->depTotals['price'] = 0.0;
		$t = [];

		$q [] = 'SELECT * FROM [e10pro_property_deps] WHERE 1';
		array_push ($q, ' AND property = %i', $this->propertyNdx);
		array_push ($q, ' AND depsPart IN %in', [0, 1]);
		array_push ($q, ' AND [docStateMain] < 4');
		array_push ($q, ' ORDER BY [dateAccounting], [rowType], [ndx]');
		$rows = $this->app()->db()->query ($q);
		$taxBalance = 0.0;
		$purchasePrice = 0.0;
		$priceIsIncreased = FALSE;
		$lastDateAccounting = NULL;
		$depsDone = FALSE;
		foreach ($rows as $r)
		{
			$item = ['pk' => $r['ndx'], 'balance' => $r['balance']];
			$item ['icon'] = ['icon' => TableDeps::$rowsIcons[$r['rowType']], 'text' => ''];
			$item ['rowType'] = ['text' => TableDeps::$rowTypesName[$r ['rowType']]];

			if (utils::dateIsBlank($r['dateAccounting']))
				$item['dateAccounting'] = ['text' => '00.00.0000', 'docAction' => 'edit', 'table' => 'e10pro.property.deps', 'pk' => $r['ndx']];
			else
				$item['dateAccounting'] = ['text' => utils::datef($r['dateAccounting'], '%d'), 'docAction' => 'edit', 'table' => 'e10pro.property.deps', 'pk' => $r['ndx']];

			if ($r['rowType'] == TableDeps::pdtIn)
			{
				if (!isset($this->depTotals['in']))
					$this->depTotals['in'] = ['total' => 0.0, 'info' => []];
				$this->depTotals['in']['total'] += $r['amount'];
				$this->depTotals['price'] += $r['amount'];
				$this->depTotals['in']['info'][] = ['text' => utils::nf($r['amount'], 2), 'prefix' => utils::datef($r['dateAccounting'], '%d'), 'class' => 'e10-prefix-left'];

				$purchasePrice = $r['amount'];
				$item ['rowType']['icon'] = 'icon-plus-square';
				$taxBalance = $r['amount'];

				if (isset($this->dg['intangible']) && $this->dg['intangible'])
				{
					$ap = $this->amortizationPeriodForDate($r['dateAccounting']);
					if (isset($ap['depLength']) && $ap['depLengthUnit'] === 'M')
						$this->monthlyDeprecation = $ap['depLength'];
				}
				$lastDateAccounting = $r['dateAccounting'];
				$this->dateBegin = $r['dateAccounting'];
			}
			elseif ($r['rowType'] == TableDeps::pdtEnhancement)
			{
				if (!isset($this->depTotals['enh']))
					$this->depTotals['enh'] = ['total' => 0.0, 'info' => []];
				$this->depTotals['enh']['total'] += $r['amount'];
				$this->depTotals['price'] += $r['amount'];
				$this->depTotals['enh']['info'][] = ['text' => utils::nf($r['amount'], 2), 'prefix' => utils::datef($r['dateAccounting'], '%d'), 'class' => 'e10-prefix-left'];

				//$item ['balance'] = $r['balance'];//$r['amount'] + $taxBalance;
				$item ['rowType']['icon'] = 'icon-plus-square';
				$item ['_options']['colSpan']['rowType'] = 3;
				$item ['rowType']['text'] .= ': '.utils::nf($r['amount'], 2);

				if ($taxBalance)
					$purchasePrice += $r ['amount'];
				else
					$purchasePrice = $r ['amount'];
				//$taxBalance = $r['balance'];
				$taxBalance += $r['amount'];
				$item['balance'] = $taxBalance;
				$priceIsIncreased = TRUE;
			}
			elseif ($r['rowType'] == TableDeps::pdtDepreciation)
			{
				if (!utils::dateIsBlank($r['dateAccounting']))
				{
					$this->depTotals['taxDep']['all'] += $r['depreciation'];
					$this->depTotals['taxDep']['request'] += $r['depreciation'];
					if ($r['dateAccounting'] < $this->thisFPBegin)
					{
						$this->depTotals['taxDep']['past'] += $r['depreciation'];
						$this->depTotals['taxDep']['pastLastDate'] = $r['dateAccounting'];
					}
					if ($r['periodBegin'] <= $this->thisFPEnd && $r['periodEnd'] >= $this->thisFPBegin)
					{
						$this->depTotals['taxDep']['thisUsed'] = $r['usedDepreciation'];
						$this->depTotals['taxDep']['this'] += $r['depreciation'];
						$this->depTotals['taxDep']['thisLastDate'] = $r['dateAccounting'];
					}

					$calcFormula = '';
					if ($r['dateAccounting'])
						$forDate = $r['dateAccounting']->format('Y-m-d');
					else
						$forDate = '0000-00-00';
					$depComputed = $this->taxDeprecationValue($forDate, $r['periodBegin'], $r['periodEnd'], $r['initState'], $purchasePrice, $priceIsIncreased, $calcFormula);
					$item ['calcDep'] = ['text' => utils::nf($depComputed, 2), 'prefix' => $calcFormula, 'class' => ''];
					$this->depTotals['taxDep']['calc'] += $depComputed;

					$item ['usedDep'] = $r['usedDepreciation'];
					$item ['dep'] = $r['depreciation'];

					if ($r['depreciation'] != $depComputed)
						$item['_options']['cellClasses']['dep'] = 'e10-warning2';
					if ($r['usedDepreciation'] != $r['depreciation'])
						$item['_options']['cellClasses']['usedDep'] = 'e10-row-info';

					$item ['rowType']['icon'] = 'icon-minus-square';
					$taxBalance = $r['balance'];

					$fp = $this->fiscalPeriod($r['dateAccounting']);
					$fpId = $fp['fpid'];

					$this->balances[$fpId]['tax']['balance'] = $r['balance'];
					$this->balances[$fpId]['year'] = intval($r['dateAccounting']->format('Y'));

					$firstMonth = $this->app()->cfgItem ('options.core.firstFiscalYearMonth', 0);
					if ($firstMonth != intval(substr($fp['begin'], 5, 2)))
						$this->fullOverviewLabels = TRUE;

					if (!isset($this->depOverview[$fpId]))
						$this->depOverview[$fpId] = [
								'accDep' => 0.0, 'taxDep' => 0.0,
								'periodName' => $fp['fullName'], 'period' => $fp, 'periodBegin' => $r['periodBegin'], 'periodEnd' => $r['periodEnd'],
								'taxCalc' => [], 'accCalc' => []];
					$this->depOverview[$fpId]['taxDep'] += $r['depreciation'];
					$this->depOverview[$fpId]['taxCalc'][] = $item ['calcDep'];

					$this->yearCounter++;
					$this->monthCounter += $this->cntMonths($r['periodBegin'], $r['periodEnd']);
					$lastDateAccounting = $r['dateAccounting'];
				}
			}
			elseif ($r['rowType'] == TableDeps::pdtReduction)
			{
				if (!isset($this->depTotals['red']))
					$this->depTotals['red'] = ['total' => 0.0, 'info' => []];
				$this->depTotals['red']['total'] += $r['amount'];
				$this->depTotals['price'] -= $r['amount'];
				$this->depTotals['red']['info'][] = ['text' => utils::nf($r['amount'], 2), 'prefix' => utils::datef($r['dateAccounting'])];

//				$taxBalance = $r['balance'];
				$taxBalance -= $r['amount'];
				$item['balance'] = $taxBalance;

				//$item ['balance'] = $r['balance'];
				$item ['rowType']['icon'] = 'icon-minus-square';
				$item ['_options']['colSpan']['rowType'] = 3;
				$item ['rowType']['text'] .= ': '.utils::nf($r['amount'], 2);
				$purchasePrice -= $r ['amount'];
			}
			elseif ($r['rowType'] == TableDeps::pdtDecommission)
			{
				if (!utils::dateIsBlank($r['dateAccounting']))
				{
					$item ['taxChange'] = $r['depreciation'];
					$item ['rowType']['icon'] = 'icon-times';
					unset($item ['balance']);
					$depsDone = TRUE;

					$fp = $this->fiscalPeriod($r['dateAccounting']);
					$fpId = $fp['fpid'];
					$this->balances[$fpId]['tax']['balance'] = $taxBalance;
					$this->balances[$fpId]['year'] = intval($r['dateAccounting']->format('Y'));
					$this->balances[$fpId]['decreased'] = 1;
				}
			}

			$docState = $this->tableDeps->getDocumentState ($r);
			$docStateClass = $this->tableDeps->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
			$item['_options']['cellClasses']['icon'] = 'center '.$docStateClass;

			$this->checkRecord ($r, $item);
			$t[] = $item;
			$this->dateEnd = $r['dateAccounting'];

			if ($planOnly)
				break;
		}

		if (!$lastDateAccounting)
			$lastDateAccounting = utils::today();
		$fp = ($this->yearCounter) ? $this->fiscalPeriodNext($lastDateAccounting) : $this->fiscalPeriod($lastDateAccounting);

		$taxUsedDepreciation = 1.0;
		while ($taxBalance > 0 && $taxUsedDepreciation > 0 && !$depsDone)
		{
			$periodBegin = utils::createDateTime($fp['begin']);
			$periodEnd = utils::createDateTime($fp['end']);
			$dateAccounting = utils::createDateTime($fp['end']);

			if ($this->monthlyDeprecation)
			{
				if (!$this->monthCounter)
				{
					$year = $lastDateAccounting->format('Y');
					$month = $lastDateAccounting->format('m');
					if ($month == 12)
					{
						$month = 1;
						$year++;
					}
					else
						$month++;

					$periodBegin = new \DateTime("$year-$month-01");
					$fp = $this->fiscalPeriod($periodBegin);
					$periodEnd = utils::createDateTime($fp['end']);
					$dateAccounting = utils::createDateTime($fp['end']);
				}

				$restMonths = $this->monthlyDeprecation - $this->monthCounter;
				if ($restMonths < 12)
				{
					$year = $periodBegin->format('Y');
					$month = $periodBegin->format('m');
					$month += $restMonths - 1;
					if ($month > 12)
					{
						$year++;
						$month -= 12;
					}
					if ($month < 1)
						$month = 12;
					$pe = new \DateTime("$year-$month-01");
					$periodEnd = utils::createDateTime($pe->format ('Y-M-t'));
				}
			}

			$calcFormula = '';
			$taxUsedDepreciation = $this->taxDeprecationValue($periodEnd, $periodBegin, $periodEnd, $taxBalance, $purchasePrice, $priceIsIncreased, $calcFormula);
			$taxBalance -= $taxUsedDepreciation;
			$this->depTotals['taxDep']['calc'] += $taxUsedDepreciation;

			$item = ['pk' => 0, 'dateAccounting' => $dateAccounting, 'dateAccountingDate' => $dateAccounting, 'balance' => $taxBalance];

			$addParams = '__depsPart=1&__rowType=99&__property='.$this->propertyNdx.'&__dateAccounting='.$dateAccounting->format('Y-m-d').
					'&__periodBegin='.$periodBegin->format('Y-m-d').'&__periodEnd='.$periodEnd->format('Y-m-d').
					'&__depreciation='.$taxUsedDepreciation.'&__usedDepreciation='.$taxUsedDepreciation;
			$item['dateAccounting'] = [
					'text' => utils::datef($dateAccounting, '%d'), 'type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.deps',
					'data-addparams' => $addParams, 'element' => 'span', 'btnClass' => ''
			];

			$this->depTotals['taxDep']['all'] += $taxUsedDepreciation;
			$this->depTotals['taxDep']['request'] += $taxUsedDepreciation;
			if ($periodEnd < $this->thisFPBegin)
			{
				$this->depTotals['taxDep']['past'] += $taxUsedDepreciation;
				$this->depTotals['taxDep']['pastLastDate'] = $periodEnd;
			}
			if ($periodBegin <= $this->thisFPEnd && $periodEnd >= $this->thisFPBegin)
			{
				$this->depTotals['taxDep']['thisUsed'] = $taxUsedDepreciation;
				$this->depTotals['taxDep']['this'] += $taxUsedDepreciation;
				$this->depTotals['taxDep']['thisLastDate'] = $periodEnd;
			}

			$item ['usedDep'] = $taxUsedDepreciation;
			$item ['dep'] = $taxUsedDepreciation;
			$item ['calcDep'] = ['text' => utils::nf($taxUsedDepreciation, 2), 'prefix' => $calcFormula, 'class' => ''];

			$item ['rowType']['icon'] = 'icon-minus-square';
			$item ['rowType']['text'] = TableDepreciation::$rowTypesName[TableDepreciation::pdtDepreciation];

			$item['_options'] = ['cellClasses' => ['icon' => 'center']];
			$item['_options']['class'] = 'e10-row-this';
			$item ['icon'] = ['icon' => TableDepreciation::$rowsIcons[TableDepreciation::pdtDepreciation], 'text' => ''];

			$fpId = $fp['fpid'];
			if (!isset($this->depOverview[$fpId]))
				$this->depOverview[$fpId] = ['accDep' => 0.0, 'taxDep' => 0.0,
						'periodName' => $fp['fullName'], 'period' => $fp, 'periodBegin' => $periodBegin, 'periodEnd' => $periodEnd,
						'taxCalc' => [], 'accCalc' => [], 'future' => 1];
			$this->depOverview[$fpId]['taxDep'] += $taxUsedDepreciation;
			$this->depOverview[$fpId]['taxCalc'][] = $item ['calcDep'];
			$this->balances[$fpId]['tax']['balance'] = $taxBalance;
			$this->balances[$fpId]['year'] = intval($periodEnd->format('Y'));

			$this->checkRecord (NULL, $item);
			$t[] = $item;

			$this->yearCounter++;
			$this->monthCounter += $this->cntMonths($periodBegin, $periodEnd);

			$fp = $this->fiscalPeriodNext($periodEnd);
		}

		$this->taxDepsPlan = $t;
	}

	public function taxDepsContent ()
	{
		$title = [['icon' => 'icon-sort-amount-desc', 'text' => 'Daňové odpisy', 'class' => 'h2']];

		$addParams = '__depsPart=0&__property='.$this->propertyNdx;
		$title[] = [
				'type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.deps', 'data-addparams' => $addParams, 'text' => 'Pohyb',
				'class' => 'pull-right'
		];

		$addParams = '__depsPart=1&__rowType=99&__property='.$this->propertyNdx;
		$title[] = [
				'type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.deps', 'data-addparams' => $addParams, 'text' => 'Odpis',
				'class' => 'pull-right'
		];


		$depGroups = $this->app()->cfgItem ('e10pro.property.depGroups');
		$accDepTypes = $this->app()->cfgItem ('e10pro.property.accDepTypes');
		$depTypes = $this->app()->cfgItem ('e10pro.property.depTypes');

		$dg = '';
		if ($this->dataProperty['depreciationGroup'])
			$dg = $depGroups[$this->dataProperty['depreciationGroup']]['shortName'];
		$title[] = ['text' => $dg, 'class' => 'break e10-small'];

		$taxDT = $depTypes[$this->dataProperty['depreciationType']]['fullName'];
		$title[] = ['text' => ' | Odpis: '.$taxDT, 'class' => 'e10-small'];


		$taxLength = $this->amortizationLength(NULL);
		$title[] = ['text' => ' | Doba: '.$taxLength, 'class' => 'e10-small'];

		if (count($this->taxDepsPlan))
		{
			$total = ['usedDep' => $this->depTotals['taxDep']['all'], 'dep' => $this->depTotals['taxDep']['request'],
					'calcDep' => $this->depTotals['taxDep']['calc'],
					'icon' => '∑', '_options' => ['class' => 'subtotal', 'cellClasses' => ['icon' => 'center']]];
			$this->taxDepsPlan[] = $total;

			$h = [
					'icon' => '',
					'dateAccounting' => ' Datum',
					'rowType' => '_Typ',
					'calcDep' => ' Výpočet odpisu',
					'dep' => ' Odpis',
					'usedDep' => ' Uplatněno',
					'balance' => ' Zůstatek',
			];
			$content = [
					'pane' => 'e10-pane e10-pane-table',
					'type' => 'table',
					'title' => $title,
					'header' => $h, 'table' => $this->taxDepsPlan, 'main' => TRUE
			];
		}
		else
		{
			$title[] = ['text' => 'Majetek není zařazen. Zařaďte ho tlačítkem Pohyb.', 'class' => 'break e10-error '];
			$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => $title];
		}
		return $content;
	}


	function createAccDepsPlan($planOnly = TRUE)
	{
		$this->yearCounter = 0;
		$this->monthCounter = 0;
		$this->monthlyDeprecation = 0;

		$this->depTotals['accDep'] = ['all' => 0.0, 'past' => 0.0, 'this' => 0.0, 'calc' => 0.0, 'pastLastDate' => NULL, 'thisLastDate' => NULL];

		$t = [];

		$q [] = 'SELECT * FROM [e10pro_property_deps] WHERE 1';
		array_push ($q, ' AND property = %i', $this->propertyNdx);
		array_push ($q, ' AND depsPart IN %in', [0, 2]);
		array_push ($q, ' AND [docStateMain] < 4');
		array_push ($q, ' ORDER BY [dateAccounting], [rowType], [ndx]');
		$rows = $this->app()->db()->query ($q);
		$accBalance = 0.0;
		$purchasePrice = 0.0;
		$priceIsIncreased = FALSE;
		$lastDateAccounting = NULL;
		$depsDone = FALSE;
		foreach ($rows as $r)
		{
			$item = ['pk' => $r['ndx'], 'balance' => $r['balance']];
			$item ['icon'] = ['icon' => TableDeps::$rowsIcons[$r['rowType']], 'text' => ''];
			$item ['rowType'] = ['text' => TableDeps::$rowTypesName[$r ['rowType']]];

			if (utils::dateIsBlank($r['dateAccounting']))
				$item['dateAccounting'] = ['text' => '00.00.0000', 'docAction' => 'edit', 'table' => 'e10pro.property.deps', 'pk' => $r['ndx']];
			else
				$item['dateAccounting'] = ['text' => utils::datef($r['dateAccounting'], '%d'), 'docAction' => 'edit', 'table' => 'e10pro.property.deps', 'pk' => $r['ndx']];

			if ($r['rowType'] == TableDeps::pdtIn)
			{
				$purchasePrice = $r['amount'];
				$accBalance = $r['amount'];
				$item ['rowType']['icon'] = 'icon-plus-square';

				if ($this->dataProperty['accDepType'] === 'AS' && isset($this->dg['intangible']) && $this->dg['intangible'])
				{
					$ap = $this->amortizationPeriodForDate($r['dateAccounting']);
					if (isset($ap['depLength']) && $ap['depLengthUnit'] === 'M')
						$this->monthlyDeprecation = $ap['depLength'];
				}
				elseif ($this->dataProperty['accDepType'] === 'AC')
				{
					if ($this->dataProperty['accDepLengthUnit'] == 'M')
						$this->monthlyDeprecation = $this->dataProperty['accDepLength'];
					else
						$this->monthlyDeprecation = $this->dataProperty['accDepLength'] * 12;
				}
				$lastDateAccounting = $r['dateAccounting'];
			}
			elseif ($r['rowType'] == TableDeps::pdtEnhancement)
			{
				$item ['balance'] = $r['amount'] + $accBalance;
				$item ['rowType']['icon'] = 'icon-plus-square';
				$item ['_options']['colSpan']['rowType'] = 3;
				$item ['rowType']['text'] .= ': '.utils::nf($r['amount'], 2);

				if ($accBalance)
					$purchasePrice += $r ['amount'];
				else
					$purchasePrice = $r ['amount'];

				$accBalance = $item['balance'];//$r['balance'];
				$priceIsIncreased = TRUE;
			}
			elseif ($r['rowType'] == TableDeps::pdtDepreciation)
			{
				if (!utils::dateIsBlank($r['dateAccounting']))
				{
					$this->depTotals['accDep']['all'] += $r['usedDepreciation'];
					if ($r['dateAccounting'] < $this->thisFPBegin)
					{
						$this->depTotals['accDep']['past'] += $r['usedDepreciation'];
						$this->depTotals['accDep']['pastLastDate'] = $r['dateAccounting'];
					}
					if ($r['periodBegin'] <= $this->thisFPEnd && $r['periodEnd'] >= $this->thisFPBegin)
					{
						$this->depTotals['accDep']['this'] += $r['usedDepreciation'];
						$this->depTotals['accDep']['thisLastDate'] = $r['dateAccounting'];
					}
					$item ['usedDep'] = $r['usedDepreciation'];

					$calcFormula = '';
					$forDate = $r['dateAccounting']->format('Y-m-d');

					$item ['rowType']['icon'] = 'icon-minus-square';

					$accChangeComputed = $this->accDeprecationValue($forDate, $r['periodBegin'], $r['periodEnd'], $accBalance, $purchasePrice, $priceIsIncreased, $calcFormula);
					$item ['calcDep'] = ['text' => utils::nf($accChangeComputed, 2), 'prefix' => $calcFormula, 'class' => ''];
					$accBalance = $r['balance'];

					$fp = $this->fiscalPeriod($r['dateAccounting']);
					$fpId = $fp['fpid'];
					if (!isset($this->depOverview[$fpId]))
						$this->depOverview[$fpId] = [
								'accDep' => 0.0, 'taxDep' => 0.0,
								'periodName' => $fp['fullName'], 'period' => $fp, 'periodBegin' => $r['periodBegin'], 'periodEnd' => $r['periodEnd'],
						];
					$this->depOverview[$fpId]['accDep'] += $r['usedDepreciation'];
					$this->depOverview[$fpId]['accCalc'][] = $item ['calcDep'];
					$this->depTotals['accDep']['calc'] += $accChangeComputed;
					$this->balances[$fpId]['acc']['balance'] = $r['balance'];
					$this->balances[$fpId]['year'] = intval($r['dateAccounting']->format('Y'));

					$this->yearCounter++;
					$this->monthCounter += $this->cntMonths($r['periodBegin'], $r['periodEnd']);
					$lastDateAccounting = $r['dateAccounting'];
				}
			}
			elseif ($r['rowType'] == TableDeps::pdtReduction)
			{
				$item ['balance'] = $accBalance - $r['amount'];
				$item ['rowType']['icon'] = 'icon-minus-square';
				$item ['_options']['colSpan']['rowType'] = 3;
				$item ['rowType']['text'] .= ': '.utils::nf($r['amount'], 2);
			}
			elseif ($r['rowType'] == TableDeps::pdtDecommission)
			{
				if (!utils::dateIsBlank($r['dateAccounting']))
				{
					$item ['taxChange'] = $r['usedDepreciation'];
					$item ['rowType']['icon'] = 'icon-times';
					unset($item ['balance']);

					$fp = $this->fiscalPeriod($r['dateAccounting']);
					$fpId = $fp['fpid'];
					$this->balances[$fpId]['acc']['balance'] = $accBalance;
					$this->balances[$fpId]['year'] = intval($r['dateAccounting']->format('Y'));
					$this->balances[$fpId]['decreased'] = 1;

					$depsDone = TRUE;
				}
			}

			$docState = $this->tableDeps->getDocumentState ($r);
			$docStateClass = $this->tableDeps->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
			$item['_options']['cellClasses']['icon'] = 'center '.$docStateClass;

			$this->checkRecord ($r, $item);
			$t[] = $item;

			if ($planOnly)
				break;
		}

		if (!$lastDateAccounting)
			$lastDateAccounting = utils::today();
		$fp = ($this->yearCounter) ? $this->fiscalPeriodNext($lastDateAccounting) : $this->fiscalPeriod($lastDateAccounting);
		$accUsedDepreciation = 1.0;
		while ($accBalance > 0 && $accUsedDepreciation > 0 && !$depsDone)
		{
			$periodBegin = utils::createDateTime($fp['begin']);
			$periodEnd = utils::createDateTime($fp['end']);
			$dateAccounting = utils::createDateTime($fp['end']);

			if ($this->monthlyDeprecation)
			{
				if (!$this->monthCounter)
				{
					$year = $lastDateAccounting->format('Y');
					$month = $lastDateAccounting->format('m');
					if ($month == 12)
					{
						$month = 1;
						$year++;
					}
					else
						$month++;

					$periodBegin = new \DateTime("$year-$month-01");
					$fp = $this->fiscalPeriod($periodBegin);
					$periodEnd = utils::createDateTime($fp['end']);
					$dateAccounting = utils::createDateTime($fp['end']);
				}

				$restMonths = $this->monthlyDeprecation - $this->monthCounter;
				if ($restMonths < 12 && $restMonths > 0)
				{
					$year = $periodBegin->format('Y');
					$month = $periodBegin->format('m');
					$month += $restMonths - 1;
					if ($month > 12)
					{
						$year++;
						$month -= 12;
					}
					$pe = new \DateTime("$year-$month-01");
					$periodEnd = utils::createDateTime($pe->format ('Y-M-t'));
				}
			}

			$calcFormula = '';
			$accUsedDepreciation = $this->accDeprecationValue($periodEnd, $periodBegin, $periodEnd, $accBalance, $purchasePrice, $priceIsIncreased, $calcFormula);
			$accBalance -= $accUsedDepreciation;
			$item = ['pk' => 0, 'dateAccounting' => $dateAccounting, 'dateAccountingDate' => $dateAccounting, 'balance' => $accBalance];

			$addParams = '__depsPart=2&__rowType=99&__property='.$this->propertyNdx.'&__dateAccounting='.$dateAccounting->format('Y-m-d').
					'&__periodBegin='.$periodBegin->format('Y-m-d').'&__periodEnd='.$periodEnd->format('Y-m-d').
					'&__depreciation='.$accUsedDepreciation.'&__usedDepreciation='.$accUsedDepreciation;
			$item['dateAccounting'] = [
					'text' => utils::datef($dateAccounting, '%d'), 'type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.deps',
					'data-addparams' => $addParams, 'element' => 'span', 'btnClass' => ''
			];

			$this->depTotals['accDep']['all'] += $accUsedDepreciation;
			if ($periodEnd < $this->thisFPBegin)
			{
				$this->depTotals['accDep']['past'] += $accUsedDepreciation;
				$this->depTotals['accDep']['pastLastDate'] = $periodEnd;
			}
			if ($periodBegin <= $this->thisFPEnd && $periodEnd >= $this->thisFPBegin)
			{
				$this->depTotals['accDep']['this'] += $accUsedDepreciation;
				$this->depTotals['accDep']['thisLastDate'] = $periodEnd;
			}
			$item ['usedDep'] = $accUsedDepreciation;
			$item ['calcDep'] = ['text' => utils::nf($accUsedDepreciation, 2), 'prefix' => $calcFormula, 'class' => ''];
			$this->depTotals['accDep']['calc'] += $accUsedDepreciation;

			$item ['rowType']['icon'] = 'icon-minus-square';
			$item ['rowType']['text'] = TableDepreciation::$rowTypesName[TableDepreciation::pdtDepreciation];

			$item['_options'] = ['cellClasses' => ['icon' => 'center']];
			$item['_options']['class'] = 'e10-row-this';
			$item ['icon'] = ['icon' => TableDepreciation::$rowsIcons[TableDepreciation::pdtDepreciation], 'text' => ''];

			$fpId = $fp['fpid'];
			if (!isset($this->depOverview[$fpId]))
				$this->depOverview[$fpId] = [
						'accDep' => 0.0, 'taxDep' => 0.0,
						'periodName' => $fp['fullName'], 'period' => $fp, 'periodBegin' => $periodBegin, 'periodEnd' => $periodEnd,
						'future' => 1];
			$this->depOverview[$fpId]['accDep'] += $accUsedDepreciation;
			$this->depOverview[$fpId]['accCalc'][] = $item ['calcDep'];
			$this->balances[$fpId]['acc']['balance'] = $accBalance;
			$this->balances[$fpId]['year'] = intval($periodEnd->format('Y'));

			$this->checkRecord (NULL, $item);
			$t[] = $item;

			$this->yearCounter++;
			$this->monthCounter += $this->cntMonths($periodBegin, $periodEnd);

			$fp = $this->fiscalPeriodNext($periodEnd);
		}

		$this->accDepsPlan = $t;
	}

	public function accDepsContent ()
	{
		$title = [['icon' => 'icon-sort-amount-desc', 'text' => 'Účetní odpisy', 'class' => 'h2']];

		$addParams = '__depsPart=0&__property='.$this->propertyNdx;
		$title[] = [
				'type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.deps', 'data-addparams' => $addParams, 'text' => 'Pohyb',
				'class' => 'pull-right'
		];

		$addParams = '__depsPart=2&__rowType=99&__property='.$this->propertyNdx;
		$title[] = [
				'type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.deps', 'data-addparams' => $addParams, 'text' => 'Odpis',
				'class' => 'pull-right'
		];


		$depGroups = $this->app()->cfgItem ('e10pro.property.depGroups');
		$accDepTypes = $this->app()->cfgItem ('e10pro.property.accDepTypes');
		$depTypes = $this->app()->cfgItem ('e10pro.property.depTypes');


		if ($this->dataProperty['accDepType'] == '')
			$title[] = ['text' => 'Způsob odepisování není nastaven', 'class' => 'break e10-small e10-error'];
		else
		{
			$accDT = $accDepTypes[$this->dataProperty['accDepType']]['fullName'];
			$title[] = ['text' => 'Způsob odepisování: ' . $accDT, 'class' => 'break e10-small'];
		}

		if ($this->dataProperty['accDepType'] == 'AS')
		{
			//$accLength = 'Stejně jako daňové odpisy';
			//$title[] = ['text' => ' | Doba: '.$accLength, 'class' => 'e10-small'];
		}
		elseif ($this->dataProperty['accDepType'] == 'AC')
		{
			$accLength = $this->dataProperty['accDepLength'];
			if ($this->dataProperty['accDepLengthUnit'] == 'M')
				$accLength .= ' měsíců';
			else
				$accLength .= ' roků';
			$title[] = ['text' => ' | Doba: '.$accLength, 'class' => 'e10-small'];
		}

		if (count($this->accDepsPlan))
		{
			$total = ['usedDep' => $this->depTotals['accDep']['all'], 'calcDep' => $this->depTotals['accDep']['calc'],
								'icon' => '∑', '_options' => ['class' => 'subtotal', 'cellClasses' => ['icon' => 'center']]];
			$this->accDepsPlan[] = $total;

			$h = [
					'icon' => '',
					'dateAccounting' => ' Datum',
					'rowType' => '_Typ',
					'calcDep' => ' Výpočet odpisu',
					'usedDep' => ' Odpis',
					'balance' => ' Zůstatek',
			];
			$content = [
					'pane' => 'e10-pane e10-pane-table',
					'type' => 'table',
					'title' => $title,
					'header' => $h, 'table' => $this->accDepsPlan, 'main' => TRUE
			];
		}
		else
		{
			$title[] = ['text' => 'Majetek není zařazen. Zařaďte ho tlačítkem Pohyb.', 'class' => 'break e10-error '];
			$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => $title];
		}
		return $content;
	}

	function createNonDepsPlan()
	{
		$t = [];

		$q [] = 'SELECT * FROM [e10pro_property_deps] WHERE 1';
		array_push ($q, ' AND property = %i', $this->propertyNdx);
		array_push ($q, ' AND depsPart IN %in', [0, 2]);
		array_push ($q, ' AND [docStateMain] < 4');
		array_push ($q, ' ORDER BY [dateAccounting], [rowType], [ndx]');
		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = ['pk' => $r['ndx'], 'balance' => $r['balance'], 'amount' => $r['amount']];
			$item ['icon'] = ['icon' => TableDeps::$rowsIcons[$r['rowType']], 'text' => ''];
			$item ['rowType'] = ['text' => TableDeps::$rowTypesName[$r ['rowType']]];

			$item['dateAccounting'] = ['text' => utils::datef($r['dateAccounting'], '%d'), 'docAction' => 'edit', 'table' => 'e10pro.property.deps', 'pk' => $r['ndx']];

			if ($r['rowType'] == TableDeps::pdtIn)
			{
				$item ['rowType']['icon'] = 'icon-plus-square';
			}
			elseif ($r['rowType'] == TableDeps::pdtEnhancement)
			{
				$item ['rowType']['icon'] = 'icon-plus-square';
			}
			elseif ($r['rowType'] == TableDeps::pdtDepreciation)
			{
				// ERROR!
			}
			elseif ($r['rowType'] == TableDeps::pdtReduction)
			{
				$item ['rowType']['icon'] = 'icon-minus-square';
			}
			elseif ($r['rowType'] == TableDeps::pdtDecommission)
			{
				$item ['taxChange'] = $r['usedDepreciation'];
				$item ['rowType']['icon'] = 'icon-times';
			}

			$docState = $this->tableDeps->getDocumentState ($r);
			$docStateClass = $this->tableDeps->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
			$item['_options']['cellClasses']['icon'] = 'center '.$docStateClass;

			$t[] = $item;
		}

		$this->nonDepsPlan = $t;
	}

	public function nonDepsContent ()
	{
		$title = [['icon' => 'icon-sort-amount-desc', 'text' => 'Vývoj hodnoty', 'class' => 'h2']];

		$addParams = '__depsPart=0&__property='.$this->propertyNdx;
		$title[] = [
				'type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.property.deps', 'data-addparams' => $addParams, 'text' => 'Pohyb',
				'class' => 'pull-right'
		];


		if (count($this->nonDepsPlan))
		{
			$h = [
					'icon' => '',
					'dateAccounting' => ' Datum',
					'rowType' => '_Typ',
					'amount' => ' Částka',
					'balance' => ' Zůstatek',
			];
			$content = [
					'pane' => 'e10-pane e10-pane-table',
					'type' => 'table',
					'title' => $title,
					'header' => $h, 'table' => $this->nonDepsPlan, 'main' => TRUE
			];
		}
		else
		{
			$title[] = ['text' => 'Majetek není zařazen. Zařaďte ho tlačítkem Pohyb.', 'class' => 'break e10-error '];
			$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => $title];
		}
		return $content;
	}

	function calcDeferredTax ()
	{
		$taxRates = [
				2016 => .19,
				2015 => .19,
				2014 =>	.19,
				2013 => .19,
				2012 => .19,
				2011 => .19,
				2010 => .19,
				2009 => .20,
				2008 => .21,
				2007 => .24,
				2006 => .24,
				2005 => .26,
				2004 => .24,
				2003 => .28,
				2002 => .31,
				2001 => .31,
				2000 => .31,
				1999 => .35,
				1998 => .35,
				1997 => .39,
				1996 => .39,
				1995 => .41,
				1994 => .42,
				1993 => .45,
		];

		$dtBalance = 0.0;
		$sumDt = 0.0;
		forEach ($this->balances as $fpId => $balance)
		{
			if (isset ($taxRates[$balance['year']]))
				$taxRate = $taxRates[$balance['year']];
			elseif ($balance['year'] < 1993)
				$taxRate = .45;
			elseif ($balance['year'] > 2016)
				$taxRate = .19;

			$this->balances[$fpId]['taxRate'] = $taxRate;

			if (!isset($balance['acc']['balance']))
				$this->balances[$fpId]['acc']['balance'] = 0.0;
			if (!isset($balance['tax']['balance']))
				$this->balances[$fpId]['tax']['balance'] = 0.0;

			$diff = (isset($balance['acc']) && isset($balance['acc']['balance'])) ? $balance['acc']['balance'] : 0;
			if (isset($balance['tax']) && isset($balance['tax']['balance']))
				$diff -= $balance['tax']['balance'];
			$this->balances[$fpId]['diff'] = $diff;

			$thisDt = $diff * $taxRate;
			$this->balances[$fpId]['thisDt'] = $thisDt;
			$dt = $thisDt - $sumDt;
			$this->balances[$fpId]['dt'] = $dt;
			$dtBalance = $dt;

			$sumDt += $dt;
		}
	}

	function createDeferredTaxContent()
	{
		$this->calcDeferredTax ();

		$t = [];
		foreach ($this->balances as $fpId => $balance)
		{
			//if (!isset($balance['acc']['balance']) && !isset($balance['acc']['balance']))
			//	continue;

			$do = $this->depOverview[$fpId];

			$item = [
					'pn' => $this->depsOverviewPeriodLabel ($do, TRUE),
					'taxRate' => ($balance['taxRate']*100).'%',
					'accBalance' => $balance['acc']['balance'],
					'taxBalance' => $balance['tax']['balance'],
					'diff' => $balance['diff'],
					'thisDt' => $balance['thisDt'],
					'dt' => $balance['dt'],
			];

			if (isset($do['future']))
				$item['_options']['class'] = 'e10-off';

			$t[] = $item;
		}


		$h = [
				'pn' => ' Období',
				'taxRate' => ' SD',
				'accBalance' => ' Účetní ZC', 'taxBalance' => ' Daňová ZC',
				'diff' => ' Rozdíl', 'thisDt' => ' Daň',
				'dt' => '+Odložená daň'
		];
		$content = ['header' => $h, 'table' => $t, 'params' => ['xxhideHeader' => 1]];

		return $content;
	}

	function createDepsPlan($planOnly = FALSE)
	{
		$this->createTaxDepsPlan($planOnly);
		$this->createAccDepsPlan($planOnly);

		// -- acc summary
		$accPastPercents = ($this->depTotals['accDep']['all']) ? round($this->depTotals['accDep']['past'] / $this->depTotals['accDep']['all'] * 100) : 0;
		$this->depTotals['accDep']['pastInfo'] = [
				'text' => utils::nf($this->depTotals['accDep']['past'], 2),
				'prefix' => utils::datef($this->depTotals['accDep']['pastLastDate']).', '.$accPastPercents.' %',
				'class' => 'e10-prefix-left',
		];

		$accThisPercents = ($this->depTotals['accDep']['all']) ? round($this->depTotals['accDep']['this'] / $this->depTotals['accDep']['all'] * 100) : 0;
		$dateInfo = ($this->depTotals['accDep']['thisLastDate']) ? utils::datef($this->depTotals['accDep']['thisLastDate']).', ' : '';
		if ($accThisPercents)
			$dateInfo .= $accThisPercents.' %';
		$this->depTotals['accDep']['thisInfo'] = [
				'text' => utils::nf($this->depTotals['accDep']['this'], 2),
				'prefix' => $dateInfo,
				'class' => 'e10-prefix-left',
		];

		$accRestAmount = $this->depTotals['price'] - $this->depTotals['accDep']['past'] - $this->depTotals['accDep']['this'];
		$accRestPercents = 100 - $accPastPercents - $accThisPercents;
		$this->depTotals['accDep']['restInfo'] = [
				'text' => utils::nf($accRestAmount, 2),
				'prefix' => ($accRestPercents) ? $accRestPercents.' %' : '',
				'class' => 'e10-prefix-left',
		];

		// -- tax summary
		$taxPastPercents = ($this->depTotals['taxDep']['all']) ? round($this->depTotals['taxDep']['past'] / $this->depTotals['taxDep']['all'] * 100) : 0;
		$this->depTotals['taxDep']['pastInfo'] = [
				'text' => utils::nf($this->depTotals['taxDep']['past'], 2),
				'prefix' => utils::datef($this->depTotals['taxDep']['pastLastDate']).', '.$taxPastPercents.' %',
				'class' => 'e10-prefix-left',
		];

		$taxThisPercents = ($this->depTotals['taxDep']['all']) ? round($this->depTotals['taxDep']['this'] / $this->depTotals['taxDep']['all'] * 100) : 0;
		$dateInfo = ($this->depTotals['taxDep']['thisLastDate']) ? utils::datef($this->depTotals['taxDep']['thisLastDate']).', ' : '';
		if ($taxThisPercents)
			$dateInfo .= $taxThisPercents.' %';
		$this->depTotals['taxDep']['thisInfo'] = [
				'text' => utils::nf($this->depTotals['taxDep']['this'], 2),
				'prefix' => $dateInfo,
				'class' => 'e10-prefix-left',
		];

		$taxRestAmount = $this->depTotals['price'] - $this->depTotals['taxDep']['past'] - $this->depTotals['taxDep']['this'];
		$taxRestPercents = 100 - $taxPastPercents - $taxThisPercents;
		$this->depTotals['taxDep']['restInfo'] = [
				'text' => utils::nf($taxRestAmount, 2),
				'prefix' => ($taxRestPercents) ? $taxRestPercents.' %' : '',
				'class' => 'e10-prefix-left',
		];

	}

	public function depsOverviewContent()
	{
		$total = [
				'periodName' => 'CELKEM',
				'taxDep' => $this->depTotals['taxDep']['all'],
				'accDep' => $this->depTotals['accDep']['all'],
		];

		$this->depOverview['TOTAL'] = $total;




		$cntCols = $this->depOverviewCntCols;

		$t = [];

		$content = [];

		$t['taxDep']['type'] = 'Daňové';
		$t['accDep']['type'] = 'Účetní';
		$t['diff']['type'] = 'Rozdíl';
		$h['type'] = 'Odpisy';

		$cnt = 0;

		$colId = 0;
		foreach ($this->depOverview as $fpId => $fpContent)
		{
			$cid = 'C'.$colId;

			$t['h'][$cid] = $this->depsOverviewPeriodLabel ($fpContent);//['text' => $fpContent['periodName']];

			//$t['h'][$cid]['suffix'] = utils::datef($fpContent['periodBegin'], '%s').' - '.utils::datef($fpContent['periodEnd'], '%s');
			$t['h']['_options']['cellClasses'][$cid] = 'e10-suffix-block';

			$t['taxDep'][$cid] = $fpContent['taxDep'];
			$t['accDep'][$cid] = $fpContent['accDep'];
			$t['diff'][$cid] = $fpContent['accDep'] - $fpContent['taxDep'];

			if (isset($fpContent['future']))
			{
				$t['taxDep']['_options']['cellClasses'][$cid] = 'e10-off';
				$t['accDep']['_options']['cellClasses'][$cid] = 'e10-off';
				$t['diff']['_options']['cellClasses'][$cid] = 'e10-off';
			}

			if (isset($fpContent['error']))
				$t['taxDep']['_options']['cellClasses'][$cid] = 'e10-error';

			$cnt++;
			$colId++;

			if ($colId === $cntCols)
			{
				$t['taxDep']['type'] = 'Daňové';
				$t['accDep']['type'] = 'Účetní';
				$t['diff']['type'] = 'Rozdíl';
				$t['h']['_options']['class'] = 'subtotal';


				$tt[] = $t['h'];
				$tt[] = $t['taxDep'];
				$tt[] = $t['accDep'];
				$tt[] = $t['diff'];
				$t = [];

				$colId = 0;
			}
		}

		if ($colId != 0)
		{
			$t['taxDep']['type'] = 'Daňové';
			$t['accDep']['type'] = 'Účetní';
			$t['diff']['type'] = 'Rozdíl';
			$t['h']['_options']['class'] = 'subtotal';
			$tt[] = $t['h'];
			$tt[] = $t['taxDep'];
			$tt[] = $t['accDep'];
			$tt[] = $t['diff'];
		}


		$h = [];
		$h['type'] = 'type';
		for ($colId = 0; $colId < min($cntCols, $cnt); $colId++)
		{
			$cid = 'C'.$colId;
			$h[$cid] = ' '.$cid;
		}


		$content[] = ['header' => $h, 'table' => $tt, 'params' => ['hideHeader' => 1]];

		return $content;
	}

	public function depsOverviewContentVertical()
	{
		$total = [
				'periodName' => 'CELKEM',
				'taxDep' => $this->depTotals['taxDep']['all'],
				'accDep' => $this->depTotals['accDep']['all'],
				'_options' => ['class' => 'subtotal']
		];

		$this->depOverview['TOTAL'] = $total;

		$t = [];
		foreach ($this->depOverview as $fpId => $fpContent)
		{
			$item = [
					'pn' => /*$fpContent['periodName']*/$this->depsOverviewPeriodLabel ($fpContent, TRUE),
					'taxCalc' => $fpContent['taxCalc'],
					'taxDep' => $fpContent['taxDep'],

					'accCalc' => $fpContent['accCalc'],
					'accDep' => $fpContent['accDep'],

					'diff' => $fpContent['accDep'] - $fpContent['taxDep']
			];

			if (isset($fpContent['_options']))
				$item['_options'] = $fpContent['_options'];

			if (isset($fpContent['future']))
				$item['_options']['class'] = 'e10-off';

			$t[] = $item;

		}


		$h = [
				'pn' => ' Období',
				'taxCalc' => ' Daňový odpis', 'taxDep' => ' Uplatněno',
				'accCalc' => ' Účetní odpis', 'accDep' => ' Uplatněno',
				'diff' => ' Rozdíl'
		];
		$content = ['header' => $h, 'table' => $t, 'params' => ['xxhideHeader' => 1]];

		return $content;

	}

	function depsOverviewPeriodLabel ($fpContent, $prefix = FALSE)
	{
		$l = ['text' => $fpContent['periodName']];
		if (!isset($fpContent['period']))
			return $l;

		if ($this->fullOverviewLabels /*||
				$fpContent['period']['begin'] != $fpContent['periodBegin']->format('Y-m-d') ||
				$fpContent['period']['end'] != $fpContent['periodEnd']->format('Y-m-d')*/)
		{
			if ($prefix)
				$l['prefix'] = utils::datef($fpContent['period']['begin'], '%s') . ' - ' . utils::datef($fpContent['period']['end'], '%s');
			else
				$l['suffix'] = utils::datef($fpContent['period']['begin'], '%s') . ' - ' . utils::datef($fpContent['period']['end'], '%s');
		}

		return $l;
	}

	function createInfo ()
	{
		$this->info = [];

		$this->info['dg'] = $this->dg['id'];
		$this->info['accDT'] = $this->accDepTypes[$this->dataProperty['accDepType']]['fullName'];
		$this->info['taxDT'] = $this->depTypes[$this->dataProperty['depreciationType']]['fullName'];
		$this->info['accDTClass'] = ($this->dataProperty['accDepType'] === '') ? ' e10-error' : '';

		// -- property deps info
		$t = [];

		$t[] = ['text' => 'Odpisová skupina', 'acc' => '', 'tax' => $this->info['dg'],
				'_options' => ['cellClasses' => ['acc' => 'center', 'tax' => 'center']]];

		// -- deps type
		$t[] = ['text' => 'Způsob odepisování', 'acc' => $this->info['accDT'], 'tax' => $this->info['taxDT'], '_options' => ['cellClasses' => ['acc' => 'center'.$this->info['accDTClass'], 'tax' => 'center']]];

		// -- deps length
		$taxLength = $this->amortizationLength (NULL);

		$accLength = '';
		if ($this->dataProperty['accDepType'] == 'AS')
		{
			$accLength = $taxLength;
		}
		elseif ($this->dataProperty['accDepType'] == 'AC')
		{
			$accLength = $this->dataProperty['accDepLength'];
			if ($this->dataProperty['accDepLengthUnit'] == 'M')
				$accLength .= ' měsíců';
			else
				$accLength .= ' roků';
		}

		$t[] = ['text' => 'Životnost', 'acc' => $accLength, 'tax' => $taxLength, '_options' => ['cellClasses' => ['acc' => 'center', 'tax' => 'center']]];

		if (isset($this->depTotals['in']))
			$t[] = ['text' => 'Vstupní cena', 'acc' => $this->depTotals['in']['info'], 'tax' => $this->depTotals['in']['info']];

		if (isset($this->depTotals['enh']))
			$t[] = ['text' => 'Technické zhodnocení', 'acc' => $this->depTotals['enh']['info'], 'tax' => $this->depTotals['enh']['info']];

		if (isset($this->depTotals['red']))
			$t[] = ['text' => 'Snížení hodnoty', 'acc' => $this->depTotals['red']['info'], 'tax' => $this->depTotals['red']['info']];


		$t[] = ['text' => 'Odepsáno', 'acc' => $this->depTotals['accDep']['pastInfo'], 'tax' => $this->depTotals['taxDep']['pastInfo']];
		$t[] = ['text' => 'Letošní odpis', 'acc' => $this->depTotals['accDep']['thisInfo'], 'tax' => $this->depTotals['taxDep']['thisInfo']];
		$t[] = ['text' => 'Zůstatková hodnota', 'acc' => $this->depTotals['accDep']['restInfo'], 'tax' => $this->depTotals['taxDep']['restInfo']];

		$h = ['text' => '', 'tax' => 'Daňový okruh', 'acc' => 'Účetní okruh', '_options' => ['cellClasses' => ['acc' => 'width35 center', 'tax' => 'width35 center'], 'class' => 'header']];

		$this->info['depsInfoTable'] = $t;
		$this->info['depsInfoHeader'] = $h;
	}

	function createErrorsContent()
	{
		if (!count($this->errors))
			return FALSE;

		$t = [];
		foreach ($this->errors as $err)
		{
			$item = ['txt' => $err['text']];
			$t[] = $item;
		}

		$h = ['#' => '#', 'txt' => 'Popis'];
		$content = [
			'pane' => 'e10-pane e10-pane-table', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1],
			'title' => ['text' => 'V nastavení odpisů jsou chyby', 'icon' => 'system/iconWarning', 'class' => 'e10-error']
		];

		return $content;
	}


	function amortizationPeriodForDate ($date)
	{
		$dateStr = (!utils::dateIsBlank($date)) ? utils::createDateTime($date)->format ('Y-m-d') : utils::today('Y-m-d');
		foreach ($this->amortizationPeriod as $ap)
		{
			if ($ap['code'] != $this->dataProperty['depreciationGroup'])
				continue;

			if ($ap['to'] != '0000-00-00' && $ap['to'] < $dateStr)
				continue;
			if ($ap['from'] != '0000-00-00' && $ap['from'] > $dateStr)
				continue;

			return $ap;
		}

		return NULL;
	}

	function amortizationLength ($date)
	{
		$al = '--';
		$startDate = $date;
		if (!$startDate && $this->dateBegin)
			$startDate = $this->dateBegin;
		$dateStr = ($startDate) ? utils::createDateTime($startDate)->format ('Y-m-d') : utils::today('Y-m-d');
		foreach ($this->amortizationPeriod as $ap)
		{
			if ($ap['code'] != $this->dataProperty['depreciationGroup'])
				continue;

			if ($ap['to'] != '0000-00-00' && $ap['to'] < $dateStr)
				continue;
			if ($ap['from'] != '0000-00-00' && $ap['from'] > $dateStr)
				continue;

			if ($ap['code'] === 'X')
			{
				$al = $this->dataProperty['taxDepLength'].' roky';
				break;
			}

			if (isset($ap['value']))
				$al = $ap['value'].' roků';
			else
				$al = $ap['depLength'].(($ap['depLengthUnit'] === 'M') ? ' měsíců' : ' roků');

			break;
		}

		if (!$date && $this->dateEnd)
		{
			$endAL = $this->amortizationLength($this->dateEnd);
			if ($al != $endAL)
				$al .= ' / '.$endAL;
		}

		return $al;
	}

	function cntMonths ($periodBegin, $periodEnd)
	{
		$months = $periodEnd->format('m') - $periodBegin->format('m') + 1 + ($periodEnd->format('Y') - $periodBegin->format('Y')) * 12;
		return $months;
	}

	function accDeprecationValue($dateAccounting, $periodBegin, $periodEnd, $accBalance, $purchasePrice, $priceIsIncreased, &$calcFormula)
	{
		$calcFormula = '--';
		$deprecation = 0.0;
		switch ($this->dataProperty['accDepType'])
		{
			case 'AC':
				$accLength = $this->dataProperty['accDepLength'];
				if ($this->dataProperty['accDepLengthUnit'] != 'M')
					$accLength = $this->dataProperty['accDepLength'] * 12;
				if ($accLength > 0.0)
				{
					if ($periodBegin)
					{
						//$fd = utils::createDateTime($periodEnd);
						//$months = $fd->format('m') - $periodBegin->format('m') + 1 + ($fd->format('Y') - $periodBegin->format('Y')) * 12;
						$months = $this->cntMonths($periodBegin, $periodEnd);
						$deprecation = $purchasePrice / $accLength * $months;
						$calcFormula = "$purchasePrice / $accLength * $months";
					}
					else
					{
						$deprecation = $purchasePrice / $accLength * 12;
						$calcFormula = "$purchasePrice / $accLength * 12";
					}
				}
				break;
			case 'AZ':
				$cz = $this->dataProperty['accDepLength'];
				if ($this->dataProperty['accDepLengthUnit'] == 'M')
					$cz = ceil($this->dataProperty['accDepLength'] / 12);
				if ($periodBegin instanceof \DateTimeInterface)
				{
					$months = 12 - $periodBegin->format('m') + 1;
					$deprecation = 2*$accBalance/($cz-$this->yearCounter+1) / 12 * $months;
				}
				else
					$deprecation = 2*$accBalance/($cz-$this->yearCounter+1);
				break;
			case 'AS':
				$deprecation = $this->taxDeprecationValue($dateAccounting, $periodBegin, $periodEnd, $accBalance, $purchasePrice, $priceIsIncreased, $calcFormula);
				break;
		}
		//if ($accBalance < ceil($deprecation))
		//	$calcFormula = 'zůstatek';

		return doubleval(min(ceil($deprecation), $accBalance));
	}

	function taxDeprecationValue($dateAccounting, $periodBegin, $periodEnd, $taxBalance, $purchasePrice, $priceIsIncreased, &$calcFormula)
	{
		$deprecation = 0.0;

		$dg = $this->depGroups [$this->dataProperty['depreciationGroup']];
		$ap = $this->amortizationPeriodForDate($dateAccounting);
		$months = $this->cntMonths($periodBegin, $periodEnd);

		if ($dg['id'] === 'X')
		{
			$deprecation = $purchasePrice / $this->dataProperty['taxDepLength'];
			$calcFormula = "$purchasePrice / {$this->dataProperty['taxDepLength']}";
			return doubleval(min(ceil($deprecation), $taxBalance));
		}

		if (isset($dg['intangible']))
		{
			$depLength = $ap['depLength'] ?? 0;
			if (isset($ap['depLengthUnit']) && $ap['depLengthUnit'] === 'Y')
				$depLength *= 12;

			//$fd = utils::createDateTime($periodEnd);
			//$months = $fd->format('m') - $periodBegin->format('m') + 1 + ($fd->format('Y') - $periodBegin->format('Y')) * 12;
			if ($depLength * $months)
			{
				$deprecation = $purchasePrice / $depLength * $months;
			}
			else
			{
				$deprecation = 0;
			}
			$calcFormula = "$purchasePrice / $depLength * $months";

			return doubleval(min(ceil($deprecation), $taxBalance));
		}


		switch ($this->dataProperty['depreciationType'])
		{
			case 'AR':
				if ($ap)
				{
					if ($ap['code'] == $this->dataProperty['depreciationGroup'])
					{
						$cr = $ap['crn'];
						if ($this->yearCounter == 0)
							$cr = $ap['cr1'];
						if ($priceIsIncreased)
							$cr = $ap['cri'];
						$deprecation = $purchasePrice*$cr/100.0;
						$calcFormula = utils::nf($purchasePrice, 2)." * $cr / 100";
					}
				}
				else
					$deprecation = -1;
				break;
			case 'AZ':
				if ($ap)
				{
					if ($ap['code'] == $this->dataProperty['depreciationGroup'])
					{
						$cz = $ap['czn'];
						if ($priceIsIncreased)
							$cz = $ap['czi'];
						$deprecation = 2*$taxBalance/($cz-$this->yearCounter);
						$calcFormula = "2 * $taxBalance / ($cz - $this->yearCounter)";
						if ($this->yearCounter == 0)
						{
							$cz = $ap['cz1'];
							$deprecation = $taxBalance/$cz;
							$calcFormula = utils::nf($taxBalance, 2)." / $cz";
						}
					}
				}
				else
					$deprecation = -1;
				break;
		}

		if ($months < 12)
		{
			$deprecation *= $months / 12;
			$calcFormula .= " * ($months / 12)";
		}

		//if ($taxBalance < ceil($deprecation))
		//	$calcFormula = 'zůstatek';
		return doubleval(min(ceil($deprecation), $taxBalance));
	}

	function fiscalPeriod ($date)
	{
		$cd = (!utils::dateIsBlank($date)) ? utils::createDateTime($date)->format('Y-m-d') : utils::today()->format('Y-m-d');
		$first = NULL;
		$last = NULL;
		$periods = $this->app->cfgItem ('e10doc.acc.periods');
		foreach ($periods as $ap)
		{
			if ($first)
				$first = $ap;
			$last = $ap;

			if ($ap['begin'] > $cd || $ap['end'] < $cd)
				continue;
			$ap['fpid'] = 'E'.$ap['ndx'];
			return $ap;
		}

		$year = substr ($cd, 0, 4);
		if ($last)
			$fp = $last;

		$yearBegin = intval(substr ($ap['begin'], 0, 4));
		$yearEnd = intval(substr ($ap['end'], 0, 4));

		$fp ['mark'] = substr ($year, 2);
		$fp ['fullName'] = $year;
		$fp ['begin'] = $year.substr ($ap['begin'], 4);
		$fp ['end'] = ($year+($yearEnd - $yearBegin)).substr ($ap['end'], 4);
		$fp ['fpid'] = 'N'.$fp['end'];

		return $fp;
	}

	function fiscalPeriodNext ($date)
	{
		$nextDay = new \DateTime($date->format('Y-m-d'));
		$nextDay->add(\DateInterval::createFromDateString('+1 day'));
		$nextFp = $this->fiscalPeriod($nextDay);

		return $nextFp;
	}
}

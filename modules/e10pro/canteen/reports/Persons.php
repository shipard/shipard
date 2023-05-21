<?php

namespace e10pro\canteen\reports;
use \Shipard\Utils\Utils, E10\uiutils;


/**
 * class Persons
 */
class Persons extends \Shipard\Report\GlobalReport
{
	var $canteenNdx = 0;
	var $canteenCfg = NULL;
	var $personsType = 0;
	var $relations = [];
	var $peoplesData = [];
	var $invoices = [];
	var $totalData = [];

	var $periodBegin = NULL;
	var $periodEnd = NULL;

	var $invoicing = 0;
	var $onePerson = 0;
	var $onePersonRelationId = FALSE;

	/** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;

	var $dataLoaded = 0;

	function init ()
	{
		$this->addParam ('calendarMonth', 'calendarMonth');
		$this->addParam ('switch', 'canteen', ['title' => 'Jídelna', 'cfg' => 'e10pro.canteen.canteens', 'titleKey' => 'fn']);

		if ($this->subReportId !== 'debtors')
			$this->addParam ('switch', 'personsType', ['title' => NULL, 'switch' => [0 => 'Plátci', 1 => 'Strávníci'], 'radioBtn' => 1]);

		parent::init();

		if (!$this->canteenNdx)
			$this->canteenNdx = $this->reportParams['canteen']['value'];
		$this->canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->canteenNdx);
		if (isset($this->canteenCfg['invoicingEnabled']) && $this->canteenCfg['invoicingEnabled'])
			$this->invoicing = 1;

		if (!$this->periodBegin)
			$this->periodBegin = Utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateBegin']);

		if (!$this->periodEnd)
			$this->periodEnd = Utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateEnd']);

		$this->setInfo('icon', 'reportPersonsBilling');
		$this->setInfo('param', 'Vyúčtování stravy za období', $this->periodBegin->format('Y / m'));

		if ($this->subReportId === 'debtors')
			$this->personsType = 0;
		else
			$this->personsType = intval($this->reportParams['personsType']['value']);

		$this->tableDocsHeads = $this->app()->table('e10doc.core.heads');
	}

	function createContent ()
	{
		$this->createContent_Peoples2();

		switch ($this->subReportId)
		{
			case '':
			case 'total': $this->renderTotalData(); break;
			case 'peoples': $this->renderPeoplesData(); break;
		}
	}

	function createContent_Peoples2 ()
	{
		if ($this->dataLoaded)
			return;

		$q[] = 'SELECT orders.*, personsOrder.fullName as personOrderName,';
		array_push($q, ' personsFee.fullName as personFeeName');
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsFee ON orders.personFee = personsFee.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND orders.[docState] != %i', 9800);
		array_push($q, ' AND [date] >= %d', $this->periodBegin, ' AND [date] <= %d', $this->periodEnd);
		array_push($q, ' ORDER BY personsOrder.[lastName], personsOrder.[firstName]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (Utils::dateIsBlank($r['date']))
				continue;
			$addFoods = json_decode($r['addFoods'], TRUE);
			if (!$addFoods)
				$addFoods = [];

			if ($this->personsType == 0)
				$personNdx = ($r['personFee']) ? $r['personFee'] : $r['personOrder'];
			else
				$personNdx = $r['personOrder'];

			$dateId = $r['date']->format('Y-m-d');
			$rid = $this->loadRelation($r['date'], $personNdx);

			if ($this->personsType == 0)
				$personName = ($r['personFeeName']) ? $r['personFeeName'] : $r['personOrderName'];
			else
				$personName = $r['personOrderName'];

			if (!isset($this->peoplesData[$rid][$personNdx]))
			{
				$this->peoplesData[$rid][$personNdx] = ['personName' => $personName, 'days' => [], 'total' => ['total' => 0.0, 'main' => 0.0]];
			}
			if (!isset($this->peoplesData[$rid][$personNdx]['days'][$dateId]))
				$this->peoplesData[$rid][$personNdx]['days'][$dateId] = ['total' => 0];

			$forcePriceExt = 0;
			if ($r['food'] === 0)
			{
				//$this->peoplesData[$personNdx]['days'][$dateId]['main'][] = ['food' => 0, 'price' => 0.0];
			}
			else
			{
				$forcePriceExt = (isset($this->peoplesData[$rid][$personNdx]['days'][$dateId]['main']) && count($this->peoplesData[$rid][$personNdx]['days'][$dateId]['main'])) ? 1 : 0;
				$price = 0.0;
				$priceType = '';
				$this->loadPrice($price, $priceType, $dateId,0, $rid, $forcePriceExt);
				$this->peoplesData[$rid][$personNdx]['days'][$dateId]['main'][] = ['food' => 0, 'price' => $price];

				if (!isset($this->peoplesData[$rid][$personNdx]['days'][$dateId]['detail']['main'][$priceType]))
					$this->peoplesData[$rid][$personNdx]['days'][$dateId]['detail']['main'][$priceType] = ['count' => 0, 'priceTotal' => 0.0, 'priceItem' => $price];
				$this->peoplesData[$rid][$personNdx]['days'][$dateId]['detail']['main'][$priceType]['priceTotal'] += $price;
				$this->peoplesData[$rid][$personNdx]['days'][$dateId]['detail']['main'][$priceType]['count']++;

				$this->peoplesData[$rid][$personNdx]['days'][$dateId]['detail']['main'][$priceType] = ['count' => 0, 'priceTotal' => 0.0, 'priceItem' => $price];

				$this->peoplesData[$rid][$personNdx]['days'][$dateId]['total'] += $price;
				$this->peoplesData[$rid][$personNdx]['total']['main'] += $price;
				$this->peoplesData[$rid][$personNdx]['total']['total'] += $price;

				if (!isset($this->peoplesData[$rid][$personNdx]['detail']['main'][$priceType]))
					$this->peoplesData[$rid][$personNdx]['detail']['main'][$priceType] = ['count' => 0, 'priceTotal' => 0.0, 'priceItem' => $price];
				$this->peoplesData[$rid][$personNdx]['detail']['main'][$priceType]['priceTotal'] += $price;
				$this->peoplesData[$rid][$personNdx]['detail']['main'][$priceType]['count']++;

				if (!isset($this->totalData[$rid]['detail']['main'][$priceType]))
					$this->totalData[$rid]['detail']['main'][$priceType] = ['count' => 0, 'priceTotal' => 0.0, 'priceItem' => $price];
				$this->totalData[$rid]['detail']['main'][$priceType]['priceTotal'] += $price;
				$this->totalData[$rid]['detail']['main'][$priceType]['count']++;

				if (!isset($this->totalData[$rid]['ALL']))
					$this->totalData[$rid]['ALL'] = ['count' => 0, 'priceTotal' => 0.0];
				$this->totalData[$rid]['ALL']['priceTotal'] += $price;
				$this->totalData[$rid]['ALL']['count']++;

				if (!isset($this->totalData['ALL']['detail']['main'][$priceType]))
					$this->totalData['ALL']['detail']['main'][$priceType] = ['count' => 0, 'priceTotal' => 0.0, 'priceItem' => $price];
				$this->totalData['ALL']['detail']['main'][$priceType]['priceTotal'] += $price;
				$this->totalData['ALL']['detail']['main'][$priceType]['count']++;

				if (!isset($this->totalData['ALL']['ALL']))
					$this->totalData['ALL']['ALL'] = ['count' => 0, 'priceTotal' => 0.0];
				$this->totalData['ALL']['ALL']['priceTotal'] += $price;
				$this->totalData['ALL']['ALL']['count']++;
			}

			if (isset($this->canteenCfg['addFoods']))
			{
				foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
				{
					$afId = 'af_' . $afNdx;
					if (isset($addFoods['addFood_'.$afNdx]) && $addFoods['addFood_'.$afNdx])
					{
						$t[$personNdx]['af_' . $afNdx] = '✔';//'Ano';
						$rid = $this->loadRelation($r['date'], $personNdx);
						$price = 0.0;
						$priceType = '';
						$this->loadPrice($price, $priceType, $dateId, $afNdx, $rid, $forcePriceExt);
						$this->peoplesData[$rid][$personNdx]['days'][$dateId][$afId][] = ['price' => $price];
						$this->peoplesData[$rid][$personNdx]['days'][$dateId]['total'] += $price;

						if (isset($this->peoplesData[$rid][$personNdx]['total'][$afId]))
							$this->peoplesData[$rid][$personNdx]['total'][$afId] += $price;
						else
							$this->peoplesData[$rid][$personNdx]['total'][$afId] = $price;
						$this->peoplesData[$rid][$personNdx]['total']['total'] += $price;

						if (!isset($this->peoplesData[$rid][$personNdx]['detail'][$afId][$priceType]))
							$this->peoplesData[$rid][$personNdx]['detail'][$afId][$priceType] = ['count' => 0, 'priceTotal' => 0.0, 'priceItem' => $price];
						$this->peoplesData[$rid][$personNdx]['detail'][$afId][$priceType]['priceTotal'] += $price;
						$this->peoplesData[$rid][$personNdx]['detail'][$afId][$priceType]['count']++;

						if (!isset($this->totalData[$rid]['detail'][$afId][$priceType]))
							$this->totalData[$rid]['detail'][$afId][$priceType] = ['count' => 0, 'priceTotal' => 0.0, 'priceItem' => $price];
						$this->totalData[$rid]['detail'][$afId][$priceType]['priceTotal'] += $price;
						$this->totalData[$rid]['detail'][$afId][$priceType]['count']++;

						if (!isset($this->totalData[$rid]['ALL']))
							$this->totalData[$rid]['ALL'] = ['count' => 0, 'priceTotal' => 0.0];
						$this->totalData[$rid]['ALL']['priceTotal'] += $price;
						$this->totalData[$rid]['ALL']['count']++;

						if (!isset($this->totalData['ALL']['detail'][$afId][$priceType]))
							$this->totalData['ALL']['detail'][$afId][$priceType] = ['count' => 0, 'priceTotal' => 0.0, 'priceItem' => $price];
						$this->totalData['ALL']['detail'][$afId][$priceType]['priceTotal'] += $price;
						$this->totalData['ALL']['detail'][$afId][$priceType]['count']++;

						if (!isset($this->totalData['ALL']['ALL']))
							$this->totalData['ALL']['ALL'] = ['count' => 0, 'priceTotal' => 0.0];
						$this->totalData['ALL']['ALL']['priceTotal'] += $price;
						$this->totalData['ALL']['ALL']['count']++;
					}
				}
			}
		}

		$this->dataLoaded = 1;
	}

	function renderPeoplesData()
	{
		$h = ['#' => '#', 'dateId' => 'Datum'];
		$h['total'] = ' Celkem';
		$h['main'] = '|'.$this->canteenCfg['mainFoodTitle'];

		if (isset($this->canteenCfg['addFoods']))
		{
			foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
			{
				$h['af_' . $afNdx] = '|'.$af['fn'];
			}
		}

		foreach ($this->peoplesData as $rid => $ridPersons)
		{
			if ($this->onePersonRelationId !== FALSE && $this->onePersonRelationId !== $rid)
				continue;

			foreach ($ridPersons as $personNdx => $onePerson)
			{
				if ($this->onePerson && $this->onePerson !== $personNdx)
					continue;

				$anyFood = 0;
				$t = [];
				foreach ($onePerson['days'] as $dateId => $day)
				{
					$anyDayFood = 0;
					$dayDate = Utils::createDateTime($dateId);
					$item = ['dateId' => Utils::datef($dayDate, '%d'), 'total' => $day['total']];

					if (isset($this->peoplesData[$rid][$personNdx]['days'][$dateId]['main']))
					{
						foreach ($this->peoplesData[$rid][$personNdx]['days'][$dateId]['main'] as $i)
						{
							//if ($i['price'] === 0.0)
							//	continue;
							$item['main'][] = ['text' => Utils::nf($i['price'], 2), 'class' => 'block'];
							$anyFood++;
							$anyDayFood++;
						}
					}
					if (!isset($item['main']))
						$item['main'][] = ['text' => '×', 'class' => 'e10-off'];

					if (isset($this->canteenCfg['addFoods']))
					{
						foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
						{
							$afId = 'af_' . $afNdx;
							if (isset($this->peoplesData[$rid][$personNdx]['days'][$dateId][$afId]))
							{
								foreach ($this->peoplesData[$rid][$personNdx]['days'][$dateId][$afId] as $i)
								{
									//if ($i['price'] === 0.0)
									//	continue;
									$item[$afId][] = ['text' => Utils::nf($i['price'], 2), 'class' => 'block'];
									$anyFood++;
									$anyDayFood++;
								}
							}
							if (!isset($item[$afId]))
								$item[$afId][] = ['text' => '×', 'class' => 'e10-off'];
						}
					}

					if (!$anyDayFood)
						continue;

					$t[] = $item;
				}

				$this->peoplesData[$rid][$personNdx]['total']['dateId'] = 'CELKEM';
				$this->peoplesData[$rid][$personNdx]['total']['_options'] = ['class' => 'sumtotal'];
				$t[] = $this->peoplesData[$rid][$personNdx]['total'];

				if (!$anyFood)
					continue;

				$this->setInfo('title', $onePerson['personName']);
				$this->addContent([
					'table' => $t, 'header' => $h, 'title' => ['code' => uiutils::createReportContentHeader($this->app, $this->info)],
					'params' => ['newPage' => 2, 'sheetTitle' => 'TEST 123', 'XXXtableClass' => 'e10-print-12pt']
				]);
			}
		}
	}

	function renderTotalData()
	{
		$h = ['#' => '#', 'person' => 'Osoba'];
		$h['total'] = ' Celkem';

		if ($this->invoicing)
			$h['invoices'] = ' Faktura';

		$h['main'] = ' '.$this->canteenCfg['mainFoodTitle'];

		if (isset($this->canteenCfg['addFoods']))
		{
			foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
			{
				$h['af_' . $afNdx] = ' '.$af['fn'];
			}
		}

		$totalTable = [];
		foreach ($this->peoplesData as $rid => $ridPersons)
		{
			if (!count($ridPersons))
				continue;

			$addRelation = 0;
			if (count($this->relations) > 1)
			{
				$relation = $this->relations[$rid];
				$relationInfo = [];
				if (isset($relation['parentPersonName']))
				{
					$relationInfo[] = ['text' => $relation['parentPersonName'], 'suffix' => $relation['parentPersonId']];
					$relationInfo[] = ['text' => $relation['relationName'], 'class' => 'pull-right'];
				}
				else
				{
					$relationInfo[] = ['text' => 'Nezatřízeno', 'class' => 'e10-error'];
				}
				$title = [
					'person' => $relationInfo,
					'_options' => ['class' => 'subheader', 'colSpan' => ['person' => count($h) - 1], 'cellCss' => ['person' => 'text-align: left!important;']],
				];
				$addRelation = 1;
			}

			foreach ($ridPersons as $personNdx => $onePerson)
			{
				$anyFood = 0;
				$item = ['person' => $onePerson['personName']];

				if ($this->invoicing && isset($this->invoices[$rid][$personNdx]))
				{
					$item['invoices'] = $this->invoices[$rid][$personNdx];
				}

				if (isset($this->peoplesData[$rid][$personNdx]['detail']['main']))
				{
					$rc = 0;
					$cntCellRows = count($this->peoplesData[$rid][$personNdx]['detail']['main']);
					$priceTotal = 0.0;
					foreach ($this->peoplesData[$rid][$personNdx]['detail']['main'] as $priceId => $pf)
					{
						$class = '';
						$item['main'][] = ['text' => $pf['count'] . ' × ' . Utils::nf($pf['priceItem'], 2) . ' = ' . Utils::nf($pf['priceTotal'], 2), 'class' => $class];
						if ($rc < $cntCellRows && $cntCellRows > 1)
							$item['main'][] = ['text' => '', 'class' => 'block'];
						$rc++;
						$priceTotal += $pf['priceTotal'];
						$anyFood++;
					}
					if ($cntCellRows > 1)
						$item['main'][] = ['text' => '∑ = ' . Utils::nf($priceTotal, 2), 'class' => $class];
				}
				if (isset($this->canteenCfg['addFoods']))
				{
					foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
					{
						$afId = 'af_' . $afNdx;
						if (!isset($this->peoplesData[$rid][$personNdx]['detail'][$afId]))
							continue;
						$cntCellRows = count($this->peoplesData[$rid][$personNdx]['detail'][$afId]);
						$priceTotal = 0.0;
						$rc = 0;
						foreach ($this->peoplesData[$rid][$personNdx]['detail'][$afId] as $priceId => $pf)
						{
							$class = '';
							$item[$afId][] = ['text' => $pf['count'] . ' × ' . Utils::nf($pf['priceItem'], 2) . ' = ' . Utils::nf($pf['priceTotal'], 2), 'class' => $class];
							if ($rc < $cntCellRows && $cntCellRows > 1)
								$item[$afId][] = ['text' => '', 'class' => 'block'];
							$rc++;
							$priceTotal += $pf['priceTotal'];
							$anyFood++;
						}
						if ($cntCellRows > 1)
							$item[$afId][] = ['text' => '∑ = ' . Utils::nf($priceTotal, 2), 'class' => $class];
					}
				}

				if (!$anyFood)
					continue;

				if ($addRelation)
				{
					$totalTable[] = $title;
					$addRelation = 0;
				}


				$item['total'] = Utils::nf($this->peoplesData[$rid][$personNdx]['total']['total'], 2);
				$totalTable[] = $item;
			}

			$totalTitle = [['text' => 'CELKEM', 'class' => '']];
			if (count($this->relations) > 1)
				$totalTitle[] = $relationInfo[0];
			$itemTotal1 = ['person' => $totalTitle];
			if (isset($this->totalData[$rid]['detail']))
			{
				foreach ($this->totalData[$rid]['detail'] as $foodColId => $foodInfo)
				{
					$rc = 0;
					$cntCellRows = count($foodInfo);
					$priceTotal = 0.0;
					foreach ($foodInfo as $priceId => $pf)
					{
						$class = '';
						$itemTotal1[$foodColId][] = ['text' => $pf['count'] . ' × ' . Utils::nf($pf['priceItem'], 2) . ' = ' . Utils::nf($pf['priceTotal'], 2), 'class' => $class];
						if ($rc < $cntCellRows && $cntCellRows > 1)
							$itemTotal1[$foodColId][] = ['text' => '', 'class' => 'block'];
						$rc++;
						$priceTotal += $pf['priceTotal'];
					}
					if ($cntCellRows > 1)
						$itemTotal1['main'][] = ['text' => '∑ = ' . Utils::nf($priceTotal, 2), 'class' => $class];
				}
				$itemTotal1['total'] = $this->totalData[$rid]['ALL']['priceTotal'];
				$itemTotal1['_options'] = ['class' => 'sumtotal', 'afterSeparator' => 'separator'];

				$totalTable[] = $itemTotal1;
			}
		}

		if (count($this->relations) > 1)
		{
			$itemTotal1 = ['person' => 'C E L K E M'];
			foreach ($this->totalData['ALL']['detail'] as $foodColId => $foodInfo)
			{
				$rc = 0;
				$cntCellRows = count($foodInfo);
				$priceTotal = 0.0;
				foreach ($foodInfo as $priceId => $pf)
				{
					$class = '';
					$itemTotal1[$foodColId][] = ['text' => $pf['count'] . ' × ' . Utils::nf($pf['priceItem'], 2) . ' = ' . Utils::nf($pf['priceTotal'], 2), 'class' => $class];
					if ($rc < $cntCellRows && $cntCellRows > 1)
						$itemTotal1[$foodColId][] = ['text' => '', 'class' => 'block'];
					$rc++;
					$priceTotal += $pf['priceTotal'];
				}
				if ($cntCellRows > 1)
					$itemTotal1['main'][] = ['text' => '∑ = ' . Utils::nf($priceTotal, 2), 'class' => $class];
			}
			$itemTotal1['total'] = $this->totalData['ALL']['ALL']['priceTotal'];
			$itemTotal1['_options'] = ['class' => 'sumtotal', 'afterSeparator' => 'separator'];

			$totalTable[] = $itemTotal1;
		}

		$this->setInfo('title', 'Přehled strávníků');
		$this->addContent(['table' => $totalTable, 'header' => $h, 'main' => TRUE]);
	}

	protected function loadInvoices($relationId)
	{
		if (!$this->invoicing)
			return;

		$linkId = 'cntn-'.$this->canteenNdx.'-'.$relationId.'-'.$this->periodBegin->format('y-m');

		$q = [];
		array_push ($q, 'SELECT [heads].*');
		array_push ($q, ' FROM [e10doc_core_heads] AS [heads]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [heads].linkId = %s', $linkId);
		array_push ($q, ' ORDER BY [heads].linkId, [heads].person, [heads].ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'text' => $r['docNumber'], 'icon' => $this->tableDocsHeads->tableIcon($r), 'class' => '',
				'prefix' => Utils::nf($r['toPay'], 2),
				'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10doc.core.heads'
			];

			$this->invoices[$relationId][$r['person']][] = $item;
		}
	}

	function loadPrice(&$price, &$priceType, $date, $foodKind, $relationId, $forcePriceExt = 0)
	{
		$ct = $this->relations[$relationId]['categoryType'];

		$q[] = 'SELECT *';
		array_push($q, ' FROM [e10pro_canteen_priceList]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [foodKind] = %i', $foodKind);
		array_push($q, ' AND ([validFrom] IS NULL OR [validFrom] <= %d)', $date);
		array_push($q, ' AND ([validTo] IS NULL OR [validTo] >= %d)', $date);

		$price = 0.0;
		$row = $this->db()->query($q)->fetch();
		if ($row)
		{
			if ($ct != 1 || $forcePriceExt)
			{
				$price = $row['priceExt'];
				$priceType = 'F'.$price;
			}
			else
			{
				$price = $row['priceEmp'];
				$priceType = 'L'.$price;
			}
		}
	}

	function loadRelation($date, $person)
	{
		$finalRelationId = '';

		$q[] = 'SELECT relations.*, categories.categoryType, ';
		array_push ($q, ' parentPersons.fullName AS parentPersonName, parentPersons.id AS parentPersonId');
		array_push ($q, ' FROM [e10_persons_relations] AS relations');
		array_push ($q, ' LEFT JOIN [e10_persons_categories] AS categories ON relations.category = categories.ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS parentPersons ON relations.parentPerson = parentPersons.ndx');
		array_push ($q, ' WHERE relations.[person] = %i', $person);
		array_push ($q, ' AND (relations.validFrom IS NULL OR relations.validFrom <= %d)', $date);
		array_push ($q, ' AND (relations.validTo IS NULL OR relations.validTo >= %d)', $date);
		array_push ($q, ' AND categories.categoryType IN %in', [1, 4]);
		array_push ($q, ' AND relations.source = %i', 0);
		array_push ($q, ' AND relations.docState != %i', 9800);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$relationId = $r['category'].'-'.$r['parentPerson'];
			if (!isset($this->relations[$relationId]))
			{
				$category = $this->app()->cfgItem('e10.persons.categories.categories.' . $r['categoryType']);

				$rel = ['orders' => [], 'sum' => [], 'parentPersonName' => '---', 'parentPersonId' => '---', 'relationName' => '---', ];

				if ($r['parentPersonName'])
				{
					$rel['parentPersonName'] = $r['parentPersonName'];
					$rel['parentPersonId'] = $r['parentPersonId'];
				}

				$rel['relationName'] = $category['fn'];
				$rel['categoryType'] = $r['categoryType'];

				$this->relations[$relationId] = $rel;
			}
			if ($finalRelationId === '')
				$finalRelationId = $relationId;
			else
			{
				error_log ("====DUPLICATE==RELATION");
			}
		}

		if ($finalRelationId === '')
		{
			if (!isset($this->relations[$finalRelationId]))
			{
				$rel = ['name' => 'NEZATŘÍZENO', 'orders' => [], 'sum' => [], 'categoryType' => 1];
				$this->relations[$finalRelationId] = $rel;
			}
		}

		if (!isset($this->invoices[$finalRelationId]))
			$this->loadInvoices($finalRelationId);

		return $finalRelationId;
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'total', 'icon' => 'reportBySum', 'title' => 'Sumárně'];
		$d[] = ['id' => 'peoples', 'icon' => 'reportPersonsBilling', 'title' => 'Po Osobách'];
		return $d;
	}

	public function createReportContentHeader ($contentPart)
	{
		if ($this->subReportId === 'peoples')
			return '';

		return parent::createReportContentHeader ($contentPart);
	}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();

		$canteens = $this->app->cfgItem ('e10pro.canteen.canteens', []);
		$invoicingEnabled = 0;
		foreach ($canteens as $canteenNdx => $canteen)
		{
			if (!isset($canteen['invoicingEnabled']) || !$canteen['invoicingEnabled'])
				continue;

			$invoicingEnabled = 1;
			break;
		}

		if ($invoicingEnabled)
		{
			$buttons[] = [
				'text' => 'Vystavit faktury', 'icon' => 'docType/invoicesOut',
				'type' => 'action', 'action' => 'addwizard', 'data-class' => 'e10pro.canteen.libs.InvoicesGeneratorWizard',
				'btnClass' => 'btn-warning',
			];
		}

		return $buttons;
	}
}


<?php

namespace e10pro\canteen;

use \e10\Utility, \e10\utils;


/**
 * Class MonthEngine
 * @package e10pro\canteen
 */
class MonthEngine extends Utility
{
	var $canteenNdx = 1;
	var $canteenCfg = NULL;
	var $personsType = 0;
	var $dateBegin;
	var $dateEnd;

	// -- loaded data
	var $relations = [];
	var $totalSum = [];
	var $orders = [];
	var $debtors = [];
	var $creditors = [];

	var $personsSort = [];

	// -- change order --> fee
	var $personsOrders = [];
	var $availablePersons = [];
	var $ordersDays = [];

	public function setPeriod ($dateBegin, $dateEnd)
	{
		$this->dateBegin = $dateBegin;
		$this->dateEnd = $dateEnd;
	}

	public function loadData()
	{
		$q[] = 'SELECT orders.*, personsOrder.fullName as personOrderName, personsOrder.personalId as personOrderPersonalId,';
		array_push($q, ' personsFee.fullName as personFeeName, personsFee.personalId as personFeePersonalId');
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsFee ON orders.personFee = personsFee.ndx');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND orders.[canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND orders.[food] != %i', 0);
		array_push($q, ' AND orders.[docState] != %i', 9800);
		array_push($q, ' AND orders.[date] >= %d', $this->dateBegin, ' AND orders.[date] <= %d', $this->dateEnd);

		array_push($q, ' ORDER BY personsOrder.[lastName], personsOrder.[firstName]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (utils::dateIsBlank($r['date']))
				continue;

			if ($r['personOrder'] && !isset($this->personsSort[$r['personOrder']]))
				$this->personsSort[$r['personOrder']] = 0;
			if ($r['personFee'] && !isset($this->personsSort[$r['personFee']]))
				$this->personsSort[$r['personFee']] = 0;

			if ($this->personsType == 0)
			{
				$personNdx = ($r['personFee']) ? $r['personFee'] : $r['personOrder'];
				$personalId = ($r['personFee']) ? $r['personFeePersonalId'] : $r['personOrderPersonalId'];
			}
			else
			{
				$personNdx = $r['personOrder'];
				$personalId = $r['personOrderPersonalId'];
			}

			$dateId = $r['date']->format('Y-m-d');


			if ($this->personsType == 0)
				$personName = ($r['personFeeName']) ? $r['personFeeName'] : $r['personOrderName'];
			else
				$personName = $r['personOrderName'];

			$rid = $this->loadRelation($r['date'], $personNdx);
			$forceFullPrice = (isset($this->relations[$rid]['orders'][$personNdx][$dateId]) && $this->relations[$rid]['orders'][$personNdx][$dateId]) ? 1 : 0;
			$price = $this->loadPrice($r['date'], $rid, $forceFullPrice);

			if (!isset($this->relations[$rid]['orders'][$personNdx]))
				$this->relations[$rid]['orders'][$personNdx] = ['person' => $personName, 'personalId' => $personalId, 'sortId' => 0, 'sum' => 0, 'price' => 0.0];
			if (!isset($this->relations[$rid]['orders'][$personNdx][$dateId]))
				$this->relations[$rid]['orders'][$personNdx][$dateId] = 0;
			if (!isset($this->relations[$rid]['ordersPrice'][$personNdx][$dateId]))
				$this->relations[$rid]['ordersPrice'][$personNdx][$dateId] = 0.0;

			//$this->relations[$rid]['orders'][$personNdx]['person'] .= '_'.$r['orderNumber'].':'.$price;

			$this->relations[$rid]['orders'][$personNdx][$dateId]++;
			$this->relations[$rid]['ordersPrice'][$personNdx][$dateId] += $price;

			$this->relations[$rid]['orders'][$personNdx]['sum']++;
			$this->relations[$rid]['orders'][$personNdx]['price'] += $price;


			if (!isset($this->relations[$rid]['sum'][$dateId]))
				$this->relations[$rid]['sum'][$dateId] = 0;
			if (!isset($this->relations[$rid]['sum']['sum']))
				$this->relations[$rid]['sum']['sum'] = 0;

			$this->relations[$rid]['sum'][$dateId]++;
			$this->relations[$rid]['sum']['sum']++;

			if (!isset($this->relations[$rid]['price'][$dateId]))
				$this->relations[$rid]['price'][$dateId] = 0.0;
			if (!isset($this->relations[$rid]['price']['price']))
				$this->relations[$rid]['price']['price'] = 0;

			$this->relations[$rid]['price'][$dateId] += $price;
			$this->relations[$rid]['price']['price'] += $price;

			if (!isset($this->totalSum[$dateId]))
				$this->totalSum[$dateId] = 0;
			if (!isset($this->totalSum['sum']))
				$this->totalSum['sum'] = 0;

			$this->totalSum[$dateId]++;
			$this->totalSum['sum']++;

			if (!isset($this->totalSum['price']))
				$this->totalSum['price'] = 0.0;
			$this->totalSum['price'] += $price;

			if (!isset($this->orders[$personNdx][$dateId]))
				$this->orders[$personNdx][$dateId] = ['orders' => [], 'hasPersonFee' => 0];

			if ($r['personFee'] && $r['personFee'] !== $r['personOrder'])
			{
				$this->orders[$personNdx][$dateId]['hasPersonFee']++;

				$debtorNdx = $r['personOrder'];
				$creditorNdx = $r['personFee'];
				if (!isset($this->debtors[$debtorNdx]))
				{
					$this->debtors[$debtorNdx] = [
						'personName' => $r['personOrderName'],
						'creditors' => []
					];
				}
				if (!isset($this->debtors[$debtorNdx]['creditors'][$creditorNdx]))
				{
					$this->debtors[$debtorNdx]['creditors'][$creditorNdx] = ['personName' => $r['personFeeName'], 'days' => []];
				}

				if (!isset($this->debtors[$debtorNdx]['creditors'][$creditorNdx]['days'][$dateId]))
					$this->debtors[$debtorNdx]['creditors'][$creditorNdx]['days'][$dateId] = 0;

				$this->debtors[$debtorNdx]['creditors'][$creditorNdx]['days'][$dateId]++;
			}
		}

		$this->loadDataPersonsSort();
	}

	function loadDataPersonsSort ()
	{
		$q[] = 'SELECT ndx FROM [e10_persons_persons]';
		array_push ($q, ' WHERE [ndx] IN %in', array_keys($this->personsSort));
		array_push ($q, ' ORDER BY lastName, firstName, ndx');

		$idx = 1;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->personsSort[$r['ndx']] = $idx;
			$idx++;
		}


		foreach ($this->relations as $relationId => $relation)
		{
			foreach ($relation['orders'] as $personNdx => $personData)
			{
				$this->relations[$relationId]['orders'][$personNdx]['sortId'] = $this->personsSort[$personNdx];
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

		return $finalRelationId;
	}

	function loadPrice($date, $relationId, $forcePriceExt = 0)
	{
		$ct = $this->relations[$relationId]['categoryType'];

		$q[] = 'SELECT *';
		array_push($q, ' FROM [e10pro_canteen_priceList]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND ([validFrom] IS NULL OR [validFrom] <= %d)', $date);
		array_push($q, ' AND ([validTo] IS NULL OR [validTo] >= %d)', $date);

		$price = 0.0;
		$row = $this->db()->query($q)->fetch();
		if ($row)
		{
			if ($ct == 4 || $forcePriceExt)
				$price = $row['priceExt'];
			else
				$price = $row['priceEmp'];
		}

		return $price;
	}

	public function changeOrderToFee($resetOnly = FALSE)
	{
		$this->canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->canteenNdx);

		$this->clearExistingFeePersons();

		if ($resetOnly)
			return;

		$this->loadOrders();
		$this->loadAvailablePersons();

		$q[] = 'SELECT orders.*, personsOrder.fullName as personOrderName, personsOrder.id as personOrderId';
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsFee ON orders.personFee = personsFee.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [food] != %i', 0);
		array_push($q, ' AND orders.[docState] != %i', 9800);
		array_push($q, ' AND [date] >= %d', $this->dateBegin, ' AND [date] <= %d', $this->dateEnd);
		array_push($q, ' ORDER BY orders.[personOrder], orders.[date], orders.[ndx]');

		$lastPerson = -1;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (utils::dateIsBlank($r['date']))
				continue;

			$personNdx = $r['personOrder'];
			if (isset($this->availablePersons[$personNdx]))
				continue;

			if (!$this->testPersonFeeRelation($r['date'], $personNdx))
				continue;

			$enabledPayers = $this->enabledPayers($personNdx);

			$dateId = $r['date']->format('Y-m-d');

			if ($lastPerson !== $r['personOrder'])
			{
				$this->availablePersons = \e10\sortByOneKey($this->availablePersons, 'availableDays', TRUE, FALSE);
				$lastPerson = $r['personOrder'];
			}

			foreach ($this->availablePersons as $apndx => $ap)
			{
				if (!in_array($apndx, $enabledPayers))
					continue;

				if (!$ap['availableDays'])
					continue;

				if (!isset($this->availablePersons[$apndx][$dateId]) || !$this->availablePersons[$apndx][$dateId])
					continue;

				$this->db()->query ('UPDATE [e10pro_canteen_foodOrders] SET [personFee] = %i', $apndx, ' WHERE [ndx] = %i', $r['ndx']);

				$this->availablePersons[$apndx][$dateId] = 0;
				$ap['availableDays']--;
				break;
			}
		}
	}

	function enabledPayers ($personNdx)
	{
		$ep = [];

		$options = $this->db()->query ('SELECT ndx FROM [e10pro_canteen_personsOptions] WHERE [person] = %i', $personNdx)->fetch();
		if (!$options)
			return $ep;

		$rows = $this->db()->query ('SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', 'e10pro-canteens-payers', ' AND dstTableId = %s', 'e10.persons.persons',
			' AND doclinks.srcRecId = %i', $options['ndx']);

		foreach ($rows as $r)
			$ep[] = $r['dstRecId'];

		return $ep;
	}

	public function loadOrders()
	{
		$q[] = 'SELECT orders.*, personsOrder.fullName as personOrderName, personsOrder.id as personOrderId';
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsFee ON orders.personFee = personsFee.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [food] != %i', 0);
		array_push($q, ' AND orders.[docState] != %i', 9800);
		array_push($q, ' AND [date] >= %d', $this->dateBegin, ' AND [date] <= %d', $this->dateEnd);
		array_push($q, ' ORDER BY personsOrder.[lastName], personsOrder.[firstName]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (utils::dateIsBlank($r['date']))
				continue;

			$personNdx = $r['personOrder'];
			$dateId = $r['date']->format('Y-m-d');

			if (!in_array($dateId, $this->ordersDays))
				$this->ordersDays[] = $dateId;

			if (!isset($this->personsOrders[$personNdx]))
				$this->personsOrders[$personNdx] = ['person' => $r['personOrderName'].'_'.$r['personOrderId'], 'orders' => []];

			if (!isset($this->personsOrders[$personNdx]['orders'][$dateId]))
				$this->personsOrders[$personNdx]['orders'][$dateId] = [];

			$this->personsOrders[$personNdx]['orders'][$dateId][] = $r['ndx'];
		}
	}

	public function loadAvailablePersons()
	{
		$employerMotherPersons = [4026];

		$q[] = 'SELECT relations.*, categories.categoryType, parentPersons.fullName AS parentPersonName, parentPersons.id AS parentPersonId,';
		array_push ($q, ' persons.fullName AS personName');
		array_push ($q, ' FROM [e10_persons_relations] AS relations');
		array_push ($q, ' LEFT JOIN [e10_persons_categories] AS categories ON relations.category = categories.ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS parentPersons ON relations.parentPerson = parentPersons.ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON relations.person = persons.ndx');
		array_push ($q, ' WHERE relations.[parentPerson] IN %in', $employerMotherPersons);
		array_push ($q, ' AND (relations.validFrom IS NULL OR relations.validFrom <= %d)', $this->dateBegin);
		array_push ($q, ' AND (relations.validTo IS NULL OR relations.validTo >= %d)', $this->dateEnd);
		array_push ($q, ' AND categories.categoryType = %i', 1);
		array_push ($q, ' AND relations.source = %i', 0);
		array_push ($q, ' AND relations.docState != %i', 9800);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$personNdx = $r['person'];

			if (!isset($this->availablePersons[$personNdx]))
				$this->availablePersons[$personNdx] = [];

			$numDays = intval($this->dateBegin->format('t'));
			$dd = clone $this->dateBegin;

			if (!isset($this->availablePersons[$personNdx]['availableDays']))
				$this->availablePersons[$personNdx]['availableDays'] = 0;

			for ($day = 1; $day <= $numDays; $day++)
			{
				$dateId = $dd->format('Y-m-d');

				if (!in_array($dateId, $this->ordersDays))
				{
					$dd->add(new \DateInterval('P1D'));
					continue;
				}

				if (!isset($this->availablePersons[$personNdx][$dateId]))
					$this->availablePersons[$personNdx][$dateId] = 1;

				if (isset($this->personsOrders[$personNdx]['orders'][$dateId]) && count($this->personsOrders[$personNdx]['orders'][$dateId]))
					$this->availablePersons[$personNdx][$dateId] = 0;
				else
					$this->availablePersons[$personNdx]['availableDays']++;

				$dd->add(new \DateInterval('P1D'));
			}
		}

		$this->availablePersons = \e10\sortByOneKey($this->availablePersons, 'availableDays', TRUE, FALSE);
	}

	function clearExistingFeePersons()
	{
		$q[] = 'UPDATE [e10pro_canteen_foodOrders]';
		array_push($q, ' SET [personFee] = %i', 0);
		array_push($q, ' WHERE [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [food] != %i', 0);
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' AND [date] >= %d', $this->dateBegin, ' AND [date] <= %d', $this->dateEnd);

		$this->db()->query ($q);
	}

	function testPersonFeeRelation($date, $person)
	{
		$enabledParentPersons = isset ($this->canteenCfg['optimizePayers']) ? $this->canteenCfg['optimizePayers'] : NULL;
		if (!$enabledParentPersons)
			return FALSE;

		$q[] = 'SELECT relations.* ';
		array_push ($q, ' FROM [e10_persons_relations] AS relations');
		array_push ($q, ' LEFT JOIN [e10_persons_categories] AS categories ON relations.category = categories.ndx');
		array_push ($q, ' WHERE relations.[person] = %i', $person);
		array_push ($q, ' AND relations.[parentPerson] IN %in', $enabledParentPersons);
		array_push ($q, ' AND (relations.validFrom IS NULL OR relations.validFrom <= %d)', $date);
		array_push ($q, ' AND (relations.validTo IS NULL OR relations.validTo >= %d)', $date);
		array_push ($q, ' AND categories.categoryType IN %in', [1, 4]);
		array_push ($q, ' AND relations.source = %i', 0);
		array_push ($q, ' AND relations.docState != %i', 9800);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			return TRUE;
		}

		return FALSE;
	}
}

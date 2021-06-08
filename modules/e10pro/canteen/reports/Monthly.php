<?php

namespace e10pro\canteen\reports;

use \e10\utils, e10doc\core\e10utils;

/**
 * Class Monthly
 * @package e10pro\canteen\reports
 */
class Monthly extends \e10\GlobalReport
{
	var $canteenNdx = 1;
	var $personsType = 0;

	function init ()
	{
		$this->addParam ('calendarMonth', 'calendarMonth', ['XXXflags' => ['enableAll', 'quarters', 'halfs', 'years']]);
		$this->addParam ('switch', 'canteen', ['title' => 'Jídelna', 'cfg' => 'e10pro.canteen.canteens', 'titleKey' => 'fn']);

		if ($this->subReportId !== 'debtors')
			$this->addParam ('switch', 'personsType', ['title' => NULL, 'switch' => [0 => 'Plátci', 1 => 'Strávníci'], 'radioBtn' => 1]);

		parent::init();

		$this->setInfo('icon', 'reportMonthlyReport');
		$this->setInfo('param', 'Období', $this->reportParams ['calendarMonth']['activeTitle']);

		$this->canteenNdx = $this->reportParams['canteen']['value'];

		if ($this->subReportId === 'debtors')
			$this->personsType = 0;
		else
			$this->personsType = intval($this->reportParams['personsType']['value']);

		$this->paperOrientation = 'landscape';
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'byRelations': $this->createContent_ByRelations(); break;
			case 'peoples': $this->createContent_Peoples(); break;
			case 'debtors': $this->createContent_Debtors(); break;
		}
	}

	function createContent_Peoples ()
	{
		$q[] = 'SELECT orders.*, personsOrder.fullName as personOrderName,';
		array_push($q, ' personsFee.fullName as personFeeName');
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsFee ON orders.personFee = personsFee.ndx');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [food] != %i', 0);
		array_push($q, ' AND orders.[docState] != %i', 9800);

		$periodBegin = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateBegin']);
		$periodEnd = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateEnd']);
		array_push($q, ' AND [date] >= %d', $periodBegin, ' AND [date] <= %d', $periodEnd);

		array_push($q, ' ORDER BY personsOrder.[lastName], personsOrder.[firstName]');

		$t = [];
		$h = ['#' => '#', 'person' => 'Jméno', 'sum' => ' ∑'];
		$sum = ['sum' => 0, '_options' => ['class' => 'sumtotal']];

		$numDays = intval($periodBegin->format('t'));
		$dd = clone $periodBegin;
		for ($day = 1; $day <= $numDays; $day++)
		{
			$dateId = $dd->format('Y-m-d');
			$h[$dateId] = '|'.$dd->format('d');

			$dd->add(new \DateInterval('P1D'));
		}


		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (utils::dateIsBlank($r['date']))
				continue;

			if ($this->personsType == 0)
				$personNdx = ($r['personFee']) ? $r['personFee'] : $r['personOrder'];
			else
				$personNdx = $r['personOrder'];

			$dateId = $r['date']->format('Y-m-d');

			if ($this->personsType == 0)
				$personName = ($r['personFeeName']) ? $r['personFeeName'] : $r['personOrderName'];
			else
				$personName = $r['personOrderName'];

			if (!isset($t[$personNdx]))
				$t[$personNdx] = ['person' => $personName, 'sum' => 0];
			if (!isset($t[$personNdx][$dateId]))
				$t[$personNdx][$dateId] = 0;

			if ($r['personFee'] && $r['personFee'] !== $r['personOrder'])
				$t[$personNdx]['_options']['cellClasses'][$dateId] = 'e10-row-this';

			$t[$personNdx][$dateId]++;
			$t[$personNdx]['sum']++;

			if (!isset($sum[$dateId]))
				$sum[$dateId] = 0;

			$sum[$dateId]++;
			$sum['sum']++;
		}

		$t['SUM'] = $sum;

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);

		$this->setInfo('title', 'Počty objednaných jídel celkem');
	}

	function createContent_ByRelations ()
	{
		$dateBegin = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateBegin']);
		$dateEnd = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateEnd']);

		$me = new \e10pro\canteen\MonthEngine($this->app());
		$me->setPeriod($dateBegin, $dateEnd);
		$me->canteenNdx = $this->canteenNdx;
		$me->personsType = $this->personsType;
		$me->loadData();

		$t = [];
		$h = ['#' => '#', 'personalId' => ' Os.č.', 'person' => 'Jméno', 'sum' => ' ∑', 'price' => ' Cena'];
		$sum = ['_options' => ['class' => 'sumtotal']];

		$numDays = intval($dateBegin->format('t'));
		$dd = clone $dateBegin;
		for ($day = 1; $day <= $numDays; $day++)
		{
			$dateId = $dd->format('Y-m-d');
			$h[$dateId] = '|'.$dd->format('d');

			$dd->add(new \DateInterval('P1D'));
		}


		foreach ($me->relations as $relationId => $relation)
		{
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
				'personalId' => $relationInfo,
				'_options' => ['class' => 'subheader', 'colSpan' => ['personalId' => $numDays + 4], 'cellCss' => ['personalId' => 'text-align: left!important;']],
			];
			$t[] = $title;

			foreach (\e10\sortByOneKey($relation['orders'], 'sortId', TRUE) as $personNdx => $personData)
			{
				foreach ($personData as $key => $value)
				{
					if (isset($me->orders[$personNdx][$key]) && $me->orders[$personNdx][$key]['hasPersonFee'])
						$personData['_options']['cellClasses'][$key] = 'e10-row-this';
				}
				$t[] = $personData;
			}

			$relSum = $relation['sum'];
			$relSum['price'] = $me->relations[$relationId]['price']['price'];
			$relSum['person'] = 'Celkem';
			$relSum['_options'] = ['class' => 'subtotal', 'afterSeparator' => 'separator'];
			$t[] = $relSum;
		}

		$totalSum = $me->totalSum;

		$totalSum['person'] = 'CELKEM';
		$totalSum['_options'] = ['class' => 'sumtotal', 'XXXafterSeparator' => 'separator'];
		$t[] = $totalSum;


		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE, 'params' => ['tableClass' => 'e10-print-small']]);
		$this->setInfo('title', 'Počty objednaných jídel celkem podle firem');
	}

	function createContent_Debtors()
	{
		$dateBegin = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateBegin']);
		$dateEnd = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateEnd']);

		$me = new \e10pro\canteen\MonthEngine($this->app());
		$me->setPeriod($dateBegin, $dateEnd);
		$me->canteenNdx = $this->canteenNdx;
		$me->personsType = $this->personsType;
		$me->loadData();

		$t = [];
		$h = ['#' => '#', 'person' => 'Jméno', 'sum' => ' ∑'];
		$sum = ['_options' => ['class' => 'sumtotal']];

		$numDays = intval($dateBegin->format('t'));
		$dd = clone $dateBegin;
		for ($day = 1; $day <= $numDays; $day++)
		{
			$dateId = $dd->format('Y-m-d');
			$h[$dateId] = '|'.$dd->format('d');

			$dd->add(new \DateInterval('P1D'));
		}


		foreach ($me->debtors as $debtorNdx => $debtor)
		{
			$title = [
				'person' => $debtor['personName'],
				'_options' => ['class' => 'subheader', 'beforeSeparator' => 'separator', 'colSpan' => ['person' => $numDays + 2]]
			];
			$t[] = $title;

			foreach ($debtor['creditors'] as $creditorNdx => $creditor)
			{
				$row = ['person' => $creditor['personName']];

				$sumCnt = 0;
				foreach ($creditor['days'] as $dayId => $dayCnt)
				{
					$row[$dayId] = $dayCnt;
					$sumCnt += $dayCnt;
				}

				$row['sum'] = $sumCnt;
				$t[] = $row;
			}
		}

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);
		$this->setInfo('title', 'Přehled dlužníků');
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'byRelations', 'icon' => 'detailByCompanies', 'title' => 'Podle firem'];
		$d[] = ['id' => 'peoples', 'icon' => 'detailTotal', 'title' => 'Celkem'];
		$d[] = ['id' => 'debtors', 'icon' => 'detailDeptors', 'title' => 'Dlužníci'];
		return $d;
	}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();
		//$buttons[] = ['text' => 'Nastavit plátce', 'icon' => 'icon-cog', 'type' => 'panelaction', 'action' => 'e10doc.balance.balanceRecalc', 'class' => 'btn-danger'];

		$dateBegin = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateBegin']);
		$dateEnd = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateEnd']);

		$buttons[] = [
			'type' => 'action', 'action' => 'addwizard', 'data-class' => 'e10pro.canteen.ResetPayersWizard',
			'text' => 'Nastavit plátce', 'icon' => 'detailDeptors', 'class' => 'btn-danger',
			'data-addparams' => 'dateBegin='.$dateBegin->format('Y-m-d').'&dateEnd='.$dateEnd->format('Y-m-d').'&canteen='.$this->canteenNdx
		];

		return $buttons;
	}

}


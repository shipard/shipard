<?php

namespace e10pro\canteen\reports;

use \e10\utils, e10doc\core\e10utils;
use \e10\base\libs\UtilsBase;

/**
 * Class Daily
 * @package e10pro\canteen\reports
 */
class Daily extends \e10\GlobalReport
{
	var $canteenNdx = 1;
	var $canteenCfg = NULL;
	var $date = NULL;
	var $cntTodayFoods = 0;

	function init ()
	{
		$this->addParam ('switch', 'canteen', ['title' => 'Jídelna', 'cfg' => 'e10pro.canteen.canteens', 'titleKey' => 'fn']);
		$this->addParam ('date', 'reportDate', ['title' => 'Datum', 'defaultValue' => utils::today('d.m.Y')]);

		parent::init();
		$this->canteenNdx = $this->reportParams['canteen']['value'];
		$this->canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->canteenNdx);

		$this->setInfo('icon', 'reportDailyReport');
		$this->setInfo('param', 'Jídelna', $this->canteenCfg['fn']);

		if ($this->date === NULL)
		{
			if (utils::dateIsValid($this->reportParams['reportDate']['value'], 'd.m.Y'))
				$this->date = \DateTime::createFromFormat('d.m.Y', $this->reportParams['reportDate']['value']);
			else
				$this->date = utils::today();
		}

		$this->setInfo('title', 'Denní přehled: '.utils::datef($this->date, '%d'));

		$this->paperOrientation = 'portrait';
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'peoples': $this->createContent_Peoples(); break;
		}
	}

	function createContent_Peoples ()
	{
		$this->addTodayMenu();
		if (!$this->cntTodayFoods)
			return;

		$q[] = 'SELECT orders.*, personsOrder.fullName as personOrderName,';
		array_push($q, ' personsFee.fullName as personFeeName, [foods].foodIndex');
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsFee ON orders.personFee = personsFee.ndx');
		array_push($q, ' LEFT JOIN e10pro_canteen_menuFoods AS foods ON orders.food = foods.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [orders].[canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND orders.[docState] != %i', 9800);
		array_push($q, ' AND [orders].[date] = %d', $this->date);
		array_push($q, ' ORDER BY personsOrder.[lastName], personsOrder.[firstName]');

		$personsPks = [];
		$t = [];
		$h = ['#' => '#', 'person' => 'Jméno', 'main' => '|'.$this->canteenCfg['mainFoodTitle']];
		$colClasses = ['main' => 'width10'];
		$sum = ['main' => 0, '_options' => ['class' => 'sumtotal']];

		if (isset($this->canteenCfg['addFoods']))
		{
			foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
			{
				$h['af_' . $afNdx] = '|'.$af['fn'];
				$colClasses['af_' . $afNdx] = 'width15';
				$sum['af_' . $afNdx] = 0;
			}
		}

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (utils::dateIsBlank($r['date']))
				continue;

			$addFoods = json_decode($r['addFoods'], TRUE);
			if (!$addFoods)
				$addFoods = [];

			$personNdx = $r['personOrder'];
			$personName = $r['personOrderName'];

			if (!isset($t[$personNdx]))
				$t[$personNdx] = ['person' => $personName, 'sum' => 0];

			if ($r['food'] === 0)
			{
				$t[$personNdx]['main'][] = ['text' => '×', 'class' => 'e10-off'];
			}
			else
			{
				$mark = ($this->cntTodayFoods === 1) ? '✔' : strval($r['foodIndex']);//'Ano';
				$t[$personNdx]['main'][] = ['text' => ' '.$mark.' '];
				$sum['main']++;
			}

			$personsPks[] = $personNdx;

			if (isset($this->canteenCfg['addFoods']))
			{
				foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
				{
					if (isset($addFoods['addFood_'.$afNdx]) && $addFoods['addFood_'.$afNdx])
					{
						$t[$personNdx]['af_' . $afNdx] = '✔';//'Ano';
						$sum['af_' . $afNdx]++;
					}
					else
						$t[$personNdx]['af_'.$afNdx] = ['text' => '×', 'class' => 'e10-off'];
				}
			}
		}

		if ($this->canteenCfg['dailyReportLabels'] != NULL)
		{
			$classification = UtilsBase::loadClassification ($this->app(), 'e10.persons.persons', $personsPks);
			foreach ($classification as $personNdx => $personClsfs)
			{
				foreach ($personClsfs as $plbls)
				{
					foreach ($plbls as $plblId => $plbl)
					{
						if (!in_array($plbl['clsfItem'], $this->canteenCfg['dailyReportLabels']))
							continue;
						$t[$personNdx]['_options']['cellCss']['#'] = $plbl['css'];
						break;
					}
				}
			}
		}
		$t['SUM'] = $sum;

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $t,
			'params' => ['colClasses' => $colClasses, 'tableClass' => 'e10-print-small'], 'main' => TRUE]);
	}

	function addTodayMenu()
	{
		$todayMenu = [];
		$todayMenu['mf'] = ['title' => $this->canteenCfg['mainFoodTitle']];
		$h = ['title' => 'Jídlo'];

		if (isset($this->canteenCfg['addFoods']))
		{
			foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
				$todayMenu['af_'.$afNdx]['title'] = $af['fn'];
		}

		$q = [];
		array_push($q, 'SELECT * FROM [e10pro_canteen_menuFoods]');
		array_push($q, ' WHERE [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [date] = %d', $this->date);
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' ORDER BY [foodIndex]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$addFoods = json_decode($r['addFoods'], TRUE);
			if (!$addFoods)
				$addFoods = [];

			if (isset($this->canteenCfg['addFoods']))
			{
				foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
				{
					if (isset($addFoods['addFoodName_'.$afNdx]))
						$todayMenu['af_'.$afNdx]['fi'.$r['foodIndex']] = $addFoods['addFoodName_'.$afNdx];
				}
			}

			$todayMenu['mf']['fi'.$r['foodIndex']] = $r['foodName'];
			if (!isset($h['fi'.$r['foodIndex']]))
				$h['fi'.$r['foodIndex']] = strval($r['foodIndex']);

			$this->cntTodayFoods++;
		}

		if ($this->cntTodayFoods)
		{
			$this->addContent(['type' => 'table', 'header' => $h, 'table' => $todayMenu,
				'params' => ['tableClass' => 'e10-print-small mb1'], 'main' => TRUE]);
		}
		else
		{
			$this->addContent(['type' => 'line', 'line' => 'Na tento den není žádný jídelní lístek...']);
		}
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'peoples', 'icon' => 'detailTotal', 'title' => 'Celkem'];
		return $d;
	}
}


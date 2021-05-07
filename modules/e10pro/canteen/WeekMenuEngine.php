<?php

namespace e10pro\canteen;

use \e10\Utility, \e10\utils;


/**
 * Class WeekMenuEngine
 * @package e10pro\canteen
 */
class WeekMenuEngine extends Utility
{
	/** @var  \e10\DbTable */
	var $tableMenus;
	/** @var  \e10\DbTable */
	var $tableMenuFoods;

	var $canteenNdx = 0;
	var $canteenCfg = NULL;
	var $year = 0;
	var $week = 0;

	var $dateBegin;
	var $dateEnd;
	var $dateId;

	var $data = [];
	var $menus = [];
	var $orders = [];
	var $ordersStats = [];
	var $datesStats;
	var $peoplesOrders = [];

	var $usersOrderNumbers = NULL;

	public function init ()
	{
		$this->tableMenus = $this->app()->table('e10pro.canteen.menus');
		$this->tableMenuFoods = $this->app()->table('e10pro.canteen.menuFoods');
	}

	public function setWeek ($canteenNdx, $year, $week)
	{
		$this->canteenNdx = $canteenNdx;
		$this->year = $year;
		$this->week = $week;
		$this->dateId = sprintf('%04d-%02d', $this->year, $this->week);

		$this->dateBegin = utils::weekDate($this->week, $this->year);
		$this->dateEnd = utils::weekDate($this->week, $this->year, 7);

		$this->canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->canteenNdx);
	}

	function loadMenu ()
	{
		// -- menu
		$q[] = 'SELECT * FROM [e10pro_canteen_menus]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [dateId] = %s', $this->dateId);
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' ORDER BY [fullName]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$item['foods'] = [];
			$this->menus[$r['ndx']] = $item;
		}

		if (!count($this->menus))
			return;

		// -- foods
		$q = [];
		$q[] = 'SELECT * FROM [e10pro_canteen_menuFoods]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [menu] IN %in', array_keys($this->menus));
		//array_push($q, ' AND ([date] >= %d', $this->dateBegin, ' AND [date] <= %d)', $this->dateEnd);
		array_push($q, ' ORDER BY [date], [foodIndex]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dateId = $r['date']->format('Y-m-d');
			$item = $r->toArray();
			$item['addFoods'] = json_decode($item['addFoods'], TRUE);
			$this->menus[$r['menu']]['foods'][$dateId][$r->foodIndex] = $item;

			if (!isset($this->menus[$r['menu']]['dayCntFoods'][$dateId]))
				$this->menus[$r['menu']]['dayCntFoods'][$dateId] = 0;
			if ($r['docState'] !== 9000)
				$this->menus[$r['menu']]['dayCntFoods'][$dateId]++;
		}
	}

	function checkWeekMenu()
	{
		if (count($this->menus))
			return;

		// -- add menu
		$title = 'Jídelní lístek '.utils::datef($this->dateBegin, '%k').' - '.utils::datef($this->dateEnd, '%k');
		$newMenu = [
			'fullName' => $title, 'canteen' => $this->canteenNdx,
			'dateFrom' => $this->dateBegin, 'dateTo' => $this->dateEnd, 'dateId' => $this->dateId,
			'docState' => 1000, 'docStateMain' => 0
		];
		$newMenuNdx = $this->tableMenus->dbInsertRec($newMenu);

		// -- add foods
		$foodsCount = $this->canteenCfg['lunchMenuFoodCount'];
		$date = new \DateTime($this->dateBegin);

		for ($day = 0; $day < 5; $day++)
		{
			for ($foodIndex = 1; $foodIndex <= $foodsCount; $foodIndex++)
			{
				$newItem = ['canteen' => $this->canteenNdx, 'menu' => $newMenuNdx, 'date' => $date, 'foodIndex' => $foodIndex, 'docState' => 1000, 'docStateMain' => 0];
				$newItemNdx = $this->tableMenuFoods->dbInsertRec($newItem);
			}

			$date->add(new \DateInterval('P1D'));
		}

		$this->loadMenu();
	}

	function loadUsersOptions()
	{
		$this->usersOrderNumbers = [];

		$userNdx = $this->app()->userNdx();
		if (!$userNdx)
			return;

		$personOptions = $this->db()->query('SELECT * FROM [e10pro_canteen_personsOptions] WHERE [person] = %i', $userNdx,
			' AND [docState] = %i', 4000, ' ORDER BY [ndx]')->fetch();
		if (!$personOptions)
		{
			return;
		}

		$personsOptionsFoodsRows = $this->db()->query('SELECT * FROM [e10pro_canteen_personsOptionsFoods] ',
			'WHERE [personOptions] = %i', $personOptions['ndx'], ' ORDER BY [rowOrder]');

		$rowOrderNumber = 0;
		foreach ($personsOptionsFoodsRows as $r)
		{
			$this->usersOrderNumbers[$rowOrderNumber] = ['personOptionsFoodNdx' => $r['ndx'], 'name' => $r['name']];
			$rowOrderNumber++;
		}
	}

	function loadUserOrders ()
	{
		if (!count($this->menus))
			return;
		$userNdx = $this->app()->userNdx();
		if (!$userNdx)
			return;

		$this->loadUsersOptions();
		if (!count($this->usersOrderNumbers))
			$this->usersOrderNumbers[0] = ['personOptionsFoodNdx' => 0, 'name' => 'qqq'];

		$q[] = 'SELECT * FROM [e10pro_canteen_foodOrders]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [menu] IN %in', array_keys($this->menus));
		array_push($q, ' AND [personOrder] = %i', $userNdx);
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (utils::dateIsBlank($r['date']))
				continue;
			$dateId = $r['date']->format('Y-m-d');
			$on = $r['orderNumber'];
			$this->orders[$r['menu']][$dateId][$on] = ['foodNdx' => $r['food'], 'addFoods' => json_decode($r['addFoods'], TRUE)];
			if (!isset($this->usersOrderNumbers[$on]))
				$this->usersOrderNumbers[$on] = ['personOptionsFoodNdx' => 0, 'name' => '???'];
		}
	}

	public function loadOrdersStats()
	{
		if (!count($this->menus))
			return;

		$q[] = 'SELECT menu, [date], food, COUNT(*) AS [cnt] FROM [e10pro_canteen_foodOrders]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [food] != %i', 0);
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [menu] IN %in', array_keys($this->menus));
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' GROUP BY [menu], [date], [food]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dayId = $r['date']->format ('Y-m-d');
			$this->ordersStats[$r['menu']][$r['food']] = ['foodNdx' => $r['food'], 'cnt' => $r['cnt'], 'dayId' => $dayId];
		}

		$this->datesStats = [];

		$q = [];
		$q[] = 'SELECT [menu], [date], COUNT(*) AS [cnt] FROM [e10pro_canteen_foodOrders]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [food] != %i', 0);
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [menu] IN %in', array_keys($this->menus));
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' GROUP BY [menu], [date]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dayId = $r['date']->format ('Y-m-d');
			$this->datesStats[$r['menu']][$dayId] = ['totalCnt' => $r['cnt'], 'foods' => []];
		}

		foreach ($this->ordersStats as $menuNdx => $menuFoods)
		{
			foreach ($menuFoods as $foodNdx => $foodInfo)
			{
				$this->datesStats[$menuNdx][$foodInfo['dayId']]['foods'][$foodNdx] = $foodInfo;
			}
		}

		foreach ($this->datesStats as $menuNdx => $days)
		{
			foreach ($days as $dayId => $dayFoods)
			{
				$xxx = \e10\sortByOneKey($dayFoods['foods'], 'cnt', FALSE, FALSE);
				foreach ($xxx as $orderNdx => $ofi)
				{
					$this->datesStats[$menuNdx][$dayId]['foods'][$ofi['foodNdx']]['order'] = $orderNdx;
				}
			}
		}

		// -- addFoodsStats
		$q = [];
		$q[] = 'SELECT * FROM [e10pro_canteen_foodOrders]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [menu] IN %in', array_keys($this->menus));
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' ORDER BY [menu], [date], [food]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dayId = $r['date']->format ('Y-m-d');
			$addFoods = json_decode($r['addFoods'], TRUE);
			if ($addFoods)
			{
				foreach ($addFoods as $afId => $afCnt)
				{
					if (!isset($this->datesStats[$r['menu']][$dayId][$afId]))
						$this->datesStats[$r['menu']][$dayId][$afId] = $afCnt;
					else
						$this->datesStats[$r['menu']][$dayId][$afId] += $afCnt;
				}
			}
		}
	}

	public function loadPeoplesOrders()
	{
		if (!count($this->menus))
			return;

		$q [] = 'SELECT [orders].*, personsOrder.fullName AS personOrderName, personsOrder.id AS personOrderId,';
		array_push($q, ' foods.foodName AS foodName, foods.foodIndex AS foodIndex,');
		array_push($q, ' canteens.shortName AS canteenName, menus.fullName AS menuName');
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10pro_canteen_canteens AS canteens ON orders.canteen = canteens.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsFee ON orders.personFee = personsFee.ndx');
		array_push($q, ' LEFT JOIN e10pro_canteen_menus AS menus ON orders.menu = menus.ndx');
		array_push($q, ' LEFT JOIN e10pro_canteen_menuFoods AS foods ON orders.food = foods.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [orders].[docState] != %i', 9800);
		//array_push($q, ' AND [orders].[food] != %i', 0);
		array_push($q, ' AND [orders].[canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [orders].[menu] IN %in', array_keys($this->menus));
		array_push($q, ' ORDER BY orders.menu, personsOrder.lastName, personsOrder.fullName, [orders].orderNumber, [orders].date');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'orderNdx' => $r['ndx'],
				'personName' => $r['personOrderName'], 'personNdx' => $r['personOrder'],
				'date' => $r['date'], 'orderNumber' => $r['orderNumber'],
				'foodName' => $r['foodName'], 'foodIndex' => $r['foodIndex'], 'foodNdx' => $r['food'],
				'addFoods' => json_decode($r['addFoods'], TRUE),
				'takeDone' => $r['takeDone'], 'takeDateTime' => $r['takeDateTime'],
			];

			$this->peoplesOrders[$r['menu']][] = $item;
		}
	}

	public function run ()
	{
		$this->init();
		$this->loadMenu();
		$this->checkWeekMenu();
		$this->loadOrdersStats();
	}
}

<?php

namespace e10pro\canteen;

use \Shipard\UI\Core\WidgetBoard, \e10\utils;


/**
 * Class WidgetCanteen
 * @package e10pro\canteen
 */
class WidgetCanteen extends WidgetBoard
{
	var $canteenNdx = 0;

	/** @var  \e10pro\canteen\TableCanteens */
	var $tableCanteens;
	/** @var  \e10\DbTable */
	var $tableMenuFoods;
	var $tableMenuFoodDocStates;

	/** @var  \e10\DbTable */
	var $tableMenus;
	var $tableMenusDocStates;

	public function composeCodeWeekMenu()
	{
		$parts = explode ('-', $this->activeTopTab);
		$year = intval($parts['1']);
		$week = intval($parts['2']);

		$canteenEngine = new \e10pro\canteen\WeekMenuEngine($this->app);
		$canteenEngine->setWeek($this->canteenNdx, $year, $week);
		$canteenEngine->run();

		foreach ($canteenEngine->menus as $menuNdx => $menu)
		{
			$header = ['day' => 'Den'];
			$colClasses = ['day' => 'width10'];
			$table = [];
			foreach ($menu['foods'] as $dayId => $dayFoods)
			{
				$dayFoodStat = isset($canteenEngine->datesStats[$menuNdx][$dayId]) ? $canteenEngine->datesStats[$menuNdx][$dayId] : NULL;

				$table[$dayId] = ['day' => ['text' => utils::datef($dayId, '%n')]];
				$table[$dayId]['day'] = [
					['text' => utils::datef($dayId, '%n'), 'class' => 'h1 block'],
					['text' => utils::datef($dayId, '%k'), 'class' => 'h2 block']
				];

				foreach ($dayFoods as $foodIndex => $food)
				{
					$cellContent = [];
					$foodOrder = ($dayFoodStat !== NULL) ? $dayFoodStat['foods'][$food['ndx']]['order'] : 100;

					$foodWin = $foodOrder < $canteenEngine->canteenCfg['lunchCookFoodCount'];

					if ($food['foodName'] === '')
					{
						$cellContent[] = [
							'text' => 'Nastavit', 'type' => 'button', 'actionClass' => 'btn btn-md btn-primary width100', 'icon' => 'system/actionOpen',
							'docAction' => 'edit', 'table' => 'e10pro.canteen.menuFoods', 'pk' => $food['ndx'],
							'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
						];
					}
					else
					{
						$cellContent[] = [
							'text' => '', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-primary pull-right', 'icon' => 'system/actionOpen',
							'docAction' => 'edit', 'table' => 'e10pro.canteen.menuFoods', 'pk' => $food['ndx'],
							'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
						];

						$cellContent[] = ['text' => $food['soupName'], 'XXicon' => 'icon-spoon fa-fw', 'class' => 'block'];
						$cellContent[] = ['text' => $food['foodName'], 'XXicon' => 'icon-cutlery fa-fw', 'class' => 'block e10-bold'];

						if (isset($canteenEngine->canteenCfg['addFoods']))
						{
							foreach ($canteenEngine->canteenCfg['addFoods'] as $afNdx => $af)
							{
								if (isset($food['addFoods']['addFoodName_'.$afNdx]))
									$cellContent[] = ['text' => $food['addFoods']['addFoodName_'.$afNdx], 'prefix' => $af['fn'], 'class' => 'block'];
								else
									$cellContent[] = ['text' => '', 'prefix' => $af['fn'], 'class' => 'block'];
							}
						}
					}

					$statsProps = [];
					$ordersCount = 0;
					$ordersTotal = 0;
					if (isset($canteenEngine->ordersStats[$menuNdx][$food['ndx']]))
						$ordersCount = $canteenEngine->ordersStats[$menuNdx][$food['ndx']]['cnt'];
					if (isset($canteenEngine->datesStats[$menuNdx][$dayId]))
						$ordersTotal = $canteenEngine->datesStats[$menuNdx][$dayId]['totalCnt'];

					if ($ordersCount === 0)
						$foodWin = FALSE;

					$percents = '---';
					if ($ordersTotal)
						$percents = round($ordersCount/$ordersTotal*100, 1).' %';

					if ($food['notCooking'] && $ordersCount)
						$labelClass = 'label-danger';
					else
						$labelClass = ($foodWin) ? 'label-success' : 'label-default';

					$statsProps[] = ['text' => 'Objednáno '.$ordersCount.' / '.$ordersTotal, 'suffix' => $percents, 'class' => 'label '.$labelClass];

					$cellContent = array_merge($cellContent, $statsProps);

					$allergens = $this->tableMenuFoods->allergens($food, TRUE);
					if (count($allergens))
						$cellContent = array_merge($cellContent, $allergens);

					$table[$dayId]['F'.$foodIndex] = $cellContent;

					$styleClass = $this->tableMenuFoods->getDocumentStateInfo ($this->tableMenuFoodDocStates, $food, 'styleClass');

					$table[$dayId]['_options']['cellClasses']['F'.$foodIndex] = $styleClass;

					if (!isset($header['F' . $foodIndex]))
					{
						$header['F' . $foodIndex] = $foodIndex . '.';
						$colClasses['F' . $foodIndex] = 'width30';
					}
				}
			}

			$menuStyleClass = $this->tableMenus->getDocumentStateInfo ($this->tableMenusDocStates, $menu, 'styleClass');


			$menuTitle = [];
			$menuTitle[] = ['text' => $menu['fullName'], 'class' => 'padd5 h1', 'icon' => 'system/iconFile'];
			$menuTitle[] = [
				'text' => 'Nastavit', 'type' => 'button', 'class' => 'pull-right padd5', 'actionClass' => 'btn btn-sm btn-primary', 'icon' => 'system/actionOpen',
				'docAction' => 'edit', 'table' => 'e10pro.canteen.menus', 'pk' => $menu['ndx'],
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
			];
			$menuTitle[] = ['text' => ' ', 'class' => 'block'];

			$this->addContent([
				'pane' => 'e10-pane e10-pane-table e10-ds '.$menuStyleClass, 'title' => $menuTitle,
				'type' => 'table', 'table' => $table, 'header' => $header, 'params' => [
					'colClasses' => $colClasses
				]
			]);
		}
	}

	public function composeCodeWeekPeoplesOrders()
	{
		$parts = explode ('-', $this->activeTopTab);
		$year = intval($parts['1']);
		$week = intval($parts['2']);

		$canteenEngine = new \e10pro\canteen\WeekMenuEngine($this->app);
		$canteenEngine->setWeek($this->canteenNdx, $year, $week);
		$canteenEngine->run();
		$canteenEngine->loadPeoplesOrders();

		foreach ($canteenEngine->peoplesOrders as $menuNdx => $orders)
		{
			$header = ['#' => '#', 'person' => 'Osoba'];
			$dayColumns = [];

			$table = [];
			foreach ($orders as $order)
			{
				$dayId = $order['date']->format ('Y-m-d');
				$orderAddFoods = $order['addFoods'];

				if (!isset($dayColumns[$dayId]))
					$dayColumns[$dayId] = '|'.utils::datef($dayId, '%n %k');

				$personNdx = $order['personNdx'];
				$dayFoodStat = isset($canteenEngine->datesStats[$menuNdx][$dayId]) ? $canteenEngine->datesStats[$menuNdx][$dayId] : NULL;
				$foodOrder = ($dayFoodStat !== NULL) ? $dayFoodStat['foods'][$order['foodNdx']]['order'] : 100;
				$foodWin = $foodOrder < $canteenEngine->canteenCfg['lunchCookFoodCount'];

				$rowId = $personNdx.'-'.$order['orderNumber'];

				if (!isset($table[$rowId]))
					$table[$rowId] = ['person' => $order['personName']];

				$orderLabel = [
					'text' => $order['foodNdx'] ? strval($order['foodIndex']) : '-',
					'class' => 'label label-default',
					'title' => $order['foodNdx'] ? $order['foodName'] : 'Bez jídla'
				];
				if ($order['takeDone'])
				{
					$orderLabel['class'] = 'label label-success';
					$orderLabel['icon'] = 'icon-check-circle';
					$orderLabel['suffix'] = utils::datef($order['takeDateTime'], '%T');
				}

				$table[$rowId][$dayId][] = $orderLabel;

				$enabledAddFoods = $this->tableCanteens->addFoodsList($canteenEngine->canteenCfg, $personNdx, $order['date']);
				foreach ($enabledAddFoods as $eafNdx)
				{
					$afCfg = $canteenEngine->canteenCfg['addFoods'][$eafNdx];
					$ordered = 0;
					if ($orderAddFoods && isset($orderAddFoods['addFood_'.$eafNdx]))
						$ordered = intval($orderAddFoods['addFood_'.$eafNdx]);

					if ($ordered)
					{
						$addFoodLabel = ['text' => $afCfg['fn'], 'icon' => 'system/iconCheck', 'class' => 'label label-success'];
						$table[$rowId][$dayId][] = $addFoodLabel;
					}
				}

				//-- settings
				$settingsBtn = [
					'text' => '', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/actionSettings', 'title' => 'Upravit', 'btnClass' => 'btn-default btn-xs pull-right',
					'data-table' => 'e10.persons.persons',
					'data-class' => 'e10pro.canteen.libs.WizardModifyFoodOrder',
					'data-addparams' => 'order-ndx='.$order['orderNdx'],
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId,
				];
				$table[$rowId][$dayId][] = $settingsBtn;

				$table[$rowId]['_options']['cellTitles'][$dayId] = $order['foodName'];
				if (!$foodWin)
					$table[$rowId]['_options']['cellClasses'][$dayId] = 'e10-warning3';
			}

			$menu = $canteenEngine->menus[$menuNdx];
			$menuTitle = [];
			$menuTitle[] = ['text' => $menu['fullName'], 'class' => 'padd5 h1', 'icon' => 'icon-file-text-o'];
			$menuTitle[] = ['text' => ' ', 'class' => 'block'];

			ksort($dayColumns);
			$header = array_merge($header, $dayColumns);

			$this->addContent([
				'x-pane' => 'e10-pane e10-pane-table', 'title' => $menuTitle,
				'type' => 'table', 'table' => $table, 'header' => $header, 'main' => TRUE
			]);
		}
	}

	public function composeCodeWeekSupplierOrders()
	{
		$canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->canteenNdx);

		$body = '';
		//$body .= '<html><body>';

		$body .= "Dobrý den, <br><br>";
		$body .= "zasíláme Vám přehled objednaných jídel pro ".utils::es($canteenCfg['fn']).".<br><br>";


		$o = new \e10pro\canteen\dataView\DisplayMenuWidget($this->app());
		$o->isRemoteRequest = 1;
		$o->setRequestParams(['showAs' => 'email', 'showTotalOrders' => 1, 'canteen' => $this->canteenNdx]);
		$o->run();

		$body .= $o->data['html'];

		//$body .= '</body></html>';


		//$this->addContent(['type' => 'line', 'line' => ['code' => $meter]]);
		$this->addContent([
			'pane' => 'e10-pane e10-pane-table', 'title' => 'TEST',
			'type' => 'line', 'line' => ['code' => $body]
		]);
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = 'menu';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if (substr ($this->activeTopTab, 0, 5) === 'week-')
		{
			if ($viewerMode === 'menu')
				$this->composeCodeWeekMenu();
			elseif ($viewerMode === 'peoples')
				$this->composeCodeWeekPeoplesOrders();
			elseif ($viewerMode === 'supplier')
				$this->composeCodeWeekSupplierOrders();
		}
	}

	public function init ()
	{
		$panelId = $this->app->testGetParam('widgetPanelId');
		$parts = explode('-', $panelId);
		if (count($parts) === 2 && $parts[0] === 'canteen')
			$this->canteenNdx = intval($parts[1]);

		if (!$this->canteenNdx)
		{
			$parts = explode ('-', $this->app->testGetParam('e10-widget-topTab'));
			$this->canteenNdx = intval($parts['3']);
		}

		$this->tableCanteens = $this->app->table ('e10pro.canteen.canteens');
		$this->tableMenuFoods = $this->app->table ('e10pro.canteen.menuFoods');
		$this->tableMenuFoodDocStates = $this->tableMenuFoods->documentStates (NULL);
		$this->tableMenus = $this->app->table ('e10pro.canteen.menus');
		$this->tableMenusDocStates = $this->tableMenus->documentStates (NULL);
		$this->createTabs();

		parent::init();
	}

	function addWeeksTabs (&$tabs)
	{
		$this->addWeeksTab ($tabs, 'Tento týden', 0);
		$this->addWeeksTab ($tabs, 'Příští týden', 1);
		$this->addWeeksTab ($tabs, 'Minulý týden', -1);
		$this->addWeeksTab ($tabs, '', -2);
		$this->addWeeksTab ($tabs, '', -3);
	}

	function addWeeksTab (&$tabs, $title, $move)
	{
		$firstDay = utils::today();
		$activeDate = clone $firstDay->modify(('Monday' === $firstDay->format('l')) ? 'monday this week' : 'last monday');

		$weekDate = clone $activeDate;
		if ($move > 0)
			$weekDate->add (new \DateInterval('P'.(abs($move)*7).'D'));
		elseif ($move < 0)
			$weekDate->sub (new \DateInterval('P'.(abs($move)*7).'D'));

		$weekId = $weekDate->format('o-W').'-'.$this->canteenNdx;
		$weekYear = intval($weekDate->format('o'));
		$weekNumber = intval($weekDate->format('W'));

		if ($title !== '')
			$tabTitle = $title.' ('.utils::weekDate($weekNumber, $weekYear, 1, 'd.m').' - '.utils::weekDate($weekNumber, $weekYear, 5, 'd.m').')';
		else
			$tabTitle = utils::weekDate($weekNumber, $weekYear, 1, 'd.m').' - '.utils::weekDate($weekNumber, $weekYear, 5, 'd.m');

		$tabs['week-' . $weekId] = ['icon' => 'system/iconCalendar', 'text' => $tabTitle, 'action' => 'load-week-' . $weekId];
	}

	function createTabs ()
	{
		$tabs = [];

		$this->addWeeksTabs($tabs);

		$this->toolbar = ['tabs' => $tabs];
		$rt = [
			'viewer-mode-menu' => ['text' =>'', 'icon' => 'system/iconCutlery', 'action' => 'viewer-mode-menu'],
			'viewer-mode-peoples' => ['text' =>'', 'icon' => 'system/iconUser', 'action' => 'viewer-mode-peoples'],
			'viewer-mode-supplier' => ['text' =>'', 'icon' => 'system/iconDelivery', 'action' => 'viewer-mode-supplier'],
		];

		$this->toolbar['rightTabs'] = $rt;
	}

	public function title()
	{
		return FALSE;
	}

	public function setDefinition ($d)
	{
		$this->definition = ['class' => 'e10pro.canteen.WidgetCanteen', 'type' => 'wkfWall e10-widget-dashboard'];
	}

	public function widgetType()
	{
		$viewerMode = 'menu';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];


		return $this->definition['type'].' '.$viewerMode;
	}

}

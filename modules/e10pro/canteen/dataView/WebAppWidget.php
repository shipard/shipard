<?php

namespace e10pro\canteen\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class WebAppWidget
 * @package e10pro\canteen\dataView
 */
class WebAppWidget extends DataView
{
	var $canteenNdx = 1;
	var $canteen = NULL;
	/** @var  \e10pro\canteen\TableMenuFoods */
	var $tableMenuFoods;
	/** @var  \e10pro\canteen\TableCanteens */
	var $tableCanteens;
	/** @var  \e10\persons\TablePersons */
	var $tablePersons;

	var $usersOrderNumbers;

	var $weekParam = FALSE;
	var $showPastDays = 0;

	protected function init()
	{
		$this->tableMenuFoods = $this->app()->table('e10pro.canteen.menuFoods');
		$this->tableCanteens = $this->app()->table('e10pro.canteen.canteens');
		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->requestParams['showAs'] = 'webAppWidget';

		$this->weekParam = $this->requestParam('week', FALSE);
		$this->showPastDays = intval($this->requestParam('showPastDays', 0));

		$this->canteenNdx = $this->requestParam('canteen', 1);

		parent::init();
	}

	protected function loadData()
	{
		$this->canteen = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->canteenNdx);
		$this->data['menus'] = [];
		$this->data['userOrders'] = [];

		if (!$this->weekParam || $this->weekParam === 'this')
			$this->loadDataWeek (0, 'Tento týden');
		if (!$this->weekParam || $this->weekParam === 'next')
			$this->loadDataWeek (1, 'Příští týden');
	}

	function loadDataWeek ($move, $title)
	{
		$firstDay = utils::today();
		$activeDate = clone $firstDay->modify(('Monday' === $firstDay->format('l')) ? 'monday this week' : 'last monday');

		$weekDate = clone $activeDate;
		if ($move > 0)
			$weekDate->add (new \DateInterval('P'.(abs($move)*7).'D'));
		elseif ($move < 0)
			$weekDate->sub (new \DateInterval('P'.(abs($move)*7).'D'));

		$weekYear = intval($weekDate->format('o'));
		$weekNumber = intval($weekDate->format('W'));

		$canteenEngine = new \e10pro\canteen\WeekMenuEngine($this->app);
		$canteenEngine->setWeek($this->canteenNdx, $weekYear, $weekNumber);
		$canteenEngine->run();
		$canteenEngine->loadUserOrders();

		$this->usersOrderNumbers = $canteenEngine->usersOrderNumbers;

		foreach ($canteenEngine->menus as &$m)
			$m['widgetTitle'] = $title;

		$this->data['menus'] += $canteenEngine->menus;
		$this->data['userOrders'] += $canteenEngine->orders;
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'webAppWidget')
			return $this->renderDataAsWidget();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsWidget()
	{
		$userNdx = $this->app()->userNdx();
		$now = new \DateTime();
		$today = utils::today();
		$dateWorkingFrom = NULL;
		if (isset($this->canteen['dateWorkingFrom']))
			$dateWorkingFrom = new \DateTime($this->canteen['dateWorkingFrom']);
		$c = '';
		foreach ($this->data['menus'] as $menu)
		{
			if ($menu['docStateMain'] === 0 || $menu['docStateMain'] === 4)
				continue;
			if ($dateWorkingFrom && $dateWorkingFrom > $menu['dateTo'])
				continue;

			$moreFoods = count($this->usersOrderNumbers) - 1;

			$menuNdx = $menu['ndx'];
			$c .= "<div class='e10pro-canteen-menu e10-sc-container' data-web-action='e10pro-canteen-foodOrder' data-web-action-canteen='{$this->canteenNdx}' data-web-action-menu='$menuNdx'>";
			$c .= "<div class='row'><div class='col-md-12 e10pro-canteen-menuTitle e10-sc-title'>".utils::es($menu['widgetTitle']).'</div></div>';
			foreach ($menu['foods'] as $dayId => $dayFoods)
			{
				$foodDate = new \DateTime($dayId);
				if ($foodDate < $today && !$this->showPastDays)
					continue;
				if ($dateWorkingFrom && $dateWorkingFrom > $foodDate)
					continue;
				$closeDate = new \DateTime($dayId.' '.$this->canteen['closeOrdersTime'].':00');
				if ($this->canteen['closeOrdersDay'])
				{
					$closeDate->sub(new \DateInterval('P' . $this->canteen['closeOrdersDay'] . 'D'));
					if (isset($this->canteen['closeOrdersSkipWeekends']) && $this->canteen['closeOrdersSkipWeekends'])
					{
						while(1)
						{
							$dow = intval($closeDate->format('N')); // 1 = monday
							if ($dow < 6)
								break;
							$closeDate->sub(new \DateInterval('P1D'));
						}
					}
				}
				$dayClosed = $closeDate < $now;
				if ($menu['dayCntFoods'][$dayId] === 0)
					$dayClosed = TRUE;

				$enabledAddFoods = $this->tableCanteens->addFoodsList($this->canteen, $userNdx, $foodDate);

				$oni = 0;
				foreach ($this->usersOrderNumbers as $orderNumber => $orderNumberCfg)
				{
					$orderInfo = 0;
					$orderRec = [];
					if (isset($this->data['userOrders'][$menuNdx][$dayId][$orderNumber]))
					{
						$orderRec = $this->data['userOrders'][$menuNdx][$dayId][$orderNumber];
						$orderInfo = $this->data['userOrders'][$menuNdx][$dayId][$orderNumber]['foodNdx'];
					}
					$personOptionsFoodNdx = $orderNumberCfg['personOptionsFoodNdx'];

					$selectClass = ' selectable';
					if ($orderInfo === 0)
						$selectClass = ' selected';

					$extendedRowClass = '';
					if ($moreFoods && $moreFoods === $oni)
						$extendedRowClass = ' e10-sc-row-separator';

					$c .= "<div class='e10pro-canteen-day e10-sc-row row{$extendedRowClass}' data-web-action-date='$dayId' data-web-action-order-number='$orderNumber' data-web-action-person-options-food='$personOptionsFoodNdx'>";
					$c .= "<div class='e10pro-canteen-dayTitle e10-sc-cell e10-sc-row-title col col-md-10p'>";
					if (!$orderNumber)
						$c .= "<div class='title'>" . utils::datef($dayId, '%n %k') . '</div>';
					if ($moreFoods)
					{
						$dayTitle = ($orderNumberCfg['name'] !== '') ? $orderNumberCfg['name'] : '-';
						$c .= "<div class='title-small' style='font-size: 80%;'>" . utils::es($dayTitle) . '</div>';
					}
					if ($dayClosed)
					{
						if ($menu['dayCntFoods'][$dayId] === 0)
							$c .= "<div class='badge badge-danger'>" . utils::es('Nevaří se') . '</div>';
						else
							$c .= "<div class='badge bg-info'>" . utils::es('Uzavřeno') . '</div>';
					}
					else
					{
						//$noFoodTitle = 'Nechci jídlo';
						$noFoodTitle = 'Nechci oběd';

						$c .= "<div class='e10-sc-item e10-sc-item-btn$selectClass' data-web-action-food='0'>" . utils::es($noFoodTitle) . '</div>';

						foreach ($dayFoods as $foodIndex => $food)
						{
							$addFoodsCfg = $food['addFoods'];
							if ($addFoodsCfg)
							{
								foreach ($addFoodsCfg as $afKey => $addFoodName)
								{
									$afNdx = intval(substr(strchr($afKey, '_'), 1));
									if (!$afNdx)
										continue;
									if (!in_array($afNdx, $enabledAddFoods))
										continue;
									$afCfg = $this->canteen['addFoods'][$afNdx];

									$selectClassAddFood = 'check-box-off';
									$valueAddFood = '0';
									if (isset($orderRec['addFoods']) && isset($orderRec['addFoods']['addFood_'.$afNdx]))
										$valueAddFood = strval($orderRec['addFoods']['addFood_'.$afNdx]);

									if ($valueAddFood === '1')
										$selectClassAddFood = 'check-box-on';
									$c .= "<div class='e10-sc-check-box $selectClassAddFood' data-check-box-value-attr='data-web-action-add-food-$afNdx' data-web-action-add-food-$afNdx='$valueAddFood'><i class='fa fa-toggle-on check-box-on'></i><i class='fa fa-toggle-off check-box-off'></i> " . utils::es($afCfg['fn']) . '</div>';
								}
							}
							break;
						}
					}
					$c .= '</div>';
					foreach ($dayFoods as $foodIndex => $food)
					{
						$foodNdx = $food['ndx'];

						$selectClass = '';

						if ($dayClosed || $food['docState'] == 9000)
						{
							$selectClass = ' inactive';
							if ($orderInfo === $foodNdx)
								$selectClass .= ' selected';
						}
						else
						{
							if ($food['notCooking'] == 1)
								$selectClass = ' disabled';

							if ($orderInfo === $foodNdx)
							{
								$selectClass .= ' selected';
							} elseif ($food['notCooking'] == 0)
								$selectClass .= ' selectable';
						}

						$c .= "<div class='e10pro-canteen-food$selectClass e10-sc-cell e10-sc-item col col-md-30p' data-web-action-food='$foodNdx'>";
						$c .= "<div class='e10-sc-cell-content'>";

						$c .= "<div class='e10pro-canteen-foodSoup'>" . utils::es($food['soupName']) . '</div>';
						$c .= "<div class='e10pro-canteen-foodFood'>" . utils::es($food['foodName']);

						$addFoodsCfg = $food['addFoods'];
						if ($addFoodsCfg)
						{
							foreach ($addFoodsCfg as $afKey => $addFoodName)
							{
								$afNdx = intval(substr(strchr($afKey, '_'), 1));
								if (!$afNdx)
									continue;
								if (!in_array($afNdx, $enabledAddFoods))
									continue;
								$afCfg = $this->canteen['addFoods'][$afNdx];

								$valueAddFood = '0';
								if (isset($orderRec['addFoods']) && isset($orderRec['addFoods']['addFood_'.$afNdx]))
									$valueAddFood = strval($orderRec['addFoods']['addFood_'.$afNdx]);

								$c .= "<div class='e10pro-canteen-addFood'>";

								if ($dayClosed)
								{
									$c .= ($valueAddFood) ? "✅" : "❌";
									$c .= "&nbsp;";
								}

								$c .= "<small>".utils::es($afCfg['fn']).':</small> '.utils::es($addFoodName) . '</div>';
							}
						}

						$allergens = $this->tableMenuFoods->allergens($food, FALSE, 'badge bg-info');
						if (count($allergens))
							$c .= '&nbsp;' . $this->app()->ui()->composeTextLine($allergens);

						$c .= '</div>';
						$c .= '</div>';
						$c .= '</div>';
					}
					$oni++;
					$c .= '</div>'; // --> row
				}
			}
			$c .= '</div>'; // --> container
		}

		return $c;
	}
}



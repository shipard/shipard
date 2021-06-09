<?php

namespace e10pro\canteen\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class DisplayMenuWidget
 * @package e10pro\canteen\dataView
 */
class DisplayMenuWidget extends DataView
{
	var $canteenNdx = 1;
	var $canteen = NULL;
	var $weekParam = FALSE;

	var $showTotalOrdersParam = FALSE;
	var $emailMode = 0;

	protected function init()
	{
		$this->classId = 'e10pro.canteen.dataView.DisplayMenuWidget';
		$this->remoteElementId = 'e10pro-canteen-DisplayMenuWidget';

		if (!isset($this->requestParams['showAs']) || !$this->requestParams['showAs'])
			$this->requestParams['showAs'] = 'webAppWidget';

		$this->weekParam = $this->requestParam('week', FALSE);
		$this->canteenNdx = $this->requestParam('canteen', 1);

		$this->showTotalOrdersParam = $this->requestParam('showTotalOrders', FALSE);

		parent::init();
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'webAppWidget')
			return $this->renderDataAsWidget();
		if ($showAs === 'email')
			return $this->renderDataAsEmail();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsEmail()
	{
		$this->emailMode = 1;
		$c = '';

		if (!$this->weekParam || $this->weekParam === 'this')
		{
			$c .= $this->composeCodeWeekMenu(0, 'Tento týden');
			$c .= '<br><br>';
		}

		if (!$this->weekParam || $this->weekParam === 'next')
		{
			$c .= $this->composeCodeWeekMenu(1, 'Příští týden');
			$c .= '<br><br>';
		}

		return $c;
	}

	protected function renderDataAsWidget()
	{
		$c = '';

		$weekPanelClass = 'col-12 col-sm-12 col-lg-6';
		if ($this->weekParam)
			$weekPanelClass = 'col-12';

		$c .= "<div class='row e10-display-panel-group'>";

		if (!$this->weekParam || $this->weekParam === 'this')
		{
			$c .= "<div class='$weekPanelClass'>";
				$c .= "<div class='e10-display-panel-middle'>";
					$c .= $this->composeCodeWeekMenu(0, 'Tento týden');
				$c .= '</div>';
			$c .= '</div>';
		}

		if (!$this->weekParam || $this->weekParam === 'next')
		{
			$c .= "<div class='$weekPanelClass'>";
				$c .= "<div class='e10-display-panel-middle'>";
					$c .= $this->composeCodeWeekMenu(1, 'Příští týden');
				$c .= '</div>';
			$c .= '</div>';
		}

		$c .= '</div>';

		$c .= '<script>setTimeout (function (){console.log("!*!*!@");webActionReloadElement($("#e10pro-canteen-DisplayMenuWidget"));}, 300000);</script>';

		return $c;
	}

	public function composeCodeWeekMenu($move, $panelTitle)
	{
		$c = '';

		$todayId = utils::today('Y-m-d');
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

		$table = [];
		foreach ($canteenEngine->menus as $menuNdx => $menu)
		{
			if ($this->emailMode)
			{
				if ($menu['docState'] === 1000)
					continue;
				$c .= "<div style='font-weight: bold; font-size: 144%; padding-bottom: 4px;'>".utils::es($panelTitle).'</div>';
			}
			else
				$c .= "<div class='e10-display-panel-title e10-display-cm-panelTitle pt-1'>&nbsp;&nbsp;<i class='fa fa-angle-right'></i> ".utils::es($panelTitle).'</div>';

			$header = ['day' => 'Den', 'foodIndex' => 'Číslo', 'food' => 'Jídlo', 'cntOrders' => ' Počet objednávek'];
			$colClasses = [
				'day' => 'e10-w4rem pt-1',
				'foodIndex' => 'e10-w2rem e10-display-cm-panelSubTitle text-center',
				'food' => 'oneRow',
				'cntOrders' => 'e10-w3rem'
			];
			$table = [];
			foreach ($menu['foods'] as $dayId => $dayFoods)
			{
				$dayFoodStat = isset($canteenEngine->datesStats[$menuNdx][$dayId]) ? $canteenEngine->datesStats[$menuNdx][$dayId] : NULL;
				$dayOff = ($dayId < $todayId);

				if ($dayOff && $this->emailMode)
					continue;

				foreach ($dayFoods as $foodIndex => $food)
				{
					$foodOrder = ($dayFoodStat !== NULL && isset($dayFoodStat['foods'][$food['ndx']])) ? $dayFoodStat['foods'][$food['ndx']]['order'] : 100;
					$foodWin = $foodOrder < $canteenEngine->canteenCfg['lunchCookFoodCount'];

					$ordersCount = 0;
					$ordersTotal = 0;
					if (isset($canteenEngine->ordersStats[$menuNdx][$food['ndx']]))
						$ordersCount = $canteenEngine->ordersStats[$menuNdx][$food['ndx']]['cnt'];
					if (isset($canteenEngine->datesStats[$menuNdx][$dayId]))
						$ordersTotal = isset($canteenEngine->datesStats[$menuNdx][$dayId]['totalCnt']) ? $canteenEngine->datesStats[$menuNdx][$dayId]['totalCnt'] : 0;

					$rowId = $dayId.'-'.$foodIndex;

					if (!isset($table[$rowId]) && $foodIndex == 1)
					{
						$thisRowId = $dayId.'-X';

						$table[$thisRowId] = [];
						$table[$thisRowId]['day'] = [
							['text' => utils::datef($dayId, '%n'), 'class' => 'h1'.($dayOff ? ' e10-off-25': '')],
							['text' => utils::datef($dayId, '%k'), 'class' => '_h3 block'.($dayOff ? ' e10-off-25': '')],
						];
						$table[$thisRowId]['food'] = $food['soupName'];
						if ($this->emailMode)
						{
							$table[$thisRowId]['foodIndex'] = ['text' => 'P', 'class' => 'e10-small'];
						}
						else
							$table[$thisRowId]['foodIndex'] = ['icon' => 'icon-spoon', 'text' => '', 'class' => 'e10-small'];

						if ($this->showTotalOrdersParam)
						{
							if (count($dayFoods) > 1)
								$table[$thisRowId]['cntOrders'] = ['text' => strval($ordersTotal), 'prefix' => '∑'];
							else
								$table[$thisRowId]['cntOrders'] = '';
						}
						else
							$table[$thisRowId]['cntOrders'] = ['icon' => 'system/iconUser', 'text' => ''];

						$table[$thisRowId]['_options']['rowSpan']['day'] = count($dayFoods) + 1 + (isset($canteenEngine->canteenCfg['addFoods']) ? count($canteenEngine->canteenCfg['addFoods']) : 0);
						$table[$thisRowId]['_options']['class'] = 'separator2';


						if ($dayId === $todayId)
							$table[$thisRowId]['_options']['cellClasses']['day'] = 'e10-display-cm-rest';
						else
							$table[$thisRowId]['_options']['cellClasses']['day'] = 'e10-display-cm-panelHandle';

						$table[$thisRowId]['_options']['cellClasses']['food'] = 'e10-display-cm-panelSubTitle';
						$table[$thisRowId]['_options']['cellClasses']['foodIndex'] = 'e10-display-cm-panelSubTitle';
						$table[$thisRowId]['_options']['cellClasses']['cntOrders'] = 'e10-display-cm-panelSubTitle';

						if ($this->emailMode)
						{
							$table[$thisRowId]['_options']['cellCss']['day'] = 'background-color: #F0F0F0; font-weight: bold; vertical-align: top;';
							$table[$thisRowId]['_options']['cellCss']['food'] = 'background-color: #F0F0F0; font-weight: bold;';
							$table[$thisRowId]['_options']['cellCss']['foodIndex'] = 'background-color: #F0F0F0;font-weight: bold;';
							$table[$thisRowId]['_options']['cellCss']['cntOrders'] = 'background-color: #F0F0F0;';
						}
					}

					$table[$rowId]['day'] = '';
					$table[$rowId]['_options']['class'] = 'separator1';

					if ($ordersCount === 0)
						$foodWin = FALSE;

					$percents = 0;
					if ($ordersTotal)
						$percents = round($ordersCount/$ordersTotal*100, 1);

					$cntOrderClass = 'e10-display-cm-panelSubTitle';
					if ($food['notCooking'])
					{
						$foodClass = 'e10-display-cm-rowContent e10-del e10-display-cm-percentage-failed';
						if ($ordersCount)
							$cntOrderClass = 'e10-display-cm-failed';
					}
					else
					{
						$foodClass = 'e10-display-cm-rowContent e10-display-cm-percentage-rest';
					}

					$table[$rowId]['foodIndex'] = $foodIndex;
					$table[$rowId]['food'] = $food['foodName'];
					$table[$rowId]['cntOrders'] = $ordersCount;

					if (!$this->emailMode)
					{
						$css = "background-size: {$percents}% 100%;";
						$table[$rowId]['_options']['cellCss'] = ['food' => $css];
					}
					$table[$rowId]['_options']['cellClasses']['food'] = $foodClass;
					$table[$rowId]['_options']['cellClasses']['cntOrders'] = $cntOrderClass;
				}

				if (isset($canteenEngine->canteenCfg['addFoods']))
				{
					foreach ($canteenEngine->canteenCfg['addFoods'] as $afNdx => $af)
					{
						$thisRowId = $dayId.'-X-A-'.$afNdx;
						if (isset($food['addFoods']['addFoodName_'.$afNdx]))
						{
							$table[$thisRowId]['foodIndex'] = 'S';
							$table[$thisRowId]['food'] = $food['addFoods']['addFoodName_'.$afNdx];

							$afId = 'addFood_'.$afNdx;
							if (isset($canteenEngine->datesStats[$menuNdx][$dayId][$afId]))
							{
								$table[$thisRowId]['cntOrders'] = $canteenEngine->datesStats[$menuNdx][$dayId][$afId];
							}
						}
					}
				}
			}

			$params = ['hideHeader' => TRUE, 'forceTableClass' => 'panel', 'colClasses' => $colClasses];

			if ($this->emailMode)
			{
				$params['tableCss'] = 'border:1px solid black;border-collapse:collapse;border-spacing:0;min-width:50%;';
				foreach($header as $key => $value)
					$params['colCss'][$key] = 'border: 1px solid gray; padding: 2px;';

				$params['colCss']['cntOrders'] .= 'text-align: right;';
				$params['colCss']['day'] .= 'width: 7rem;';
				$params['colCss']['foodIndex'] .= 'width: 2rem; text-align: center;';
				$params['colCss']['cntOrders'] .= 'width: 3rem;';

				$params['nls'] = "\n";
			}

			$c .= \e10\renderTableFromArray($table, $header, $params, $this->app());
		}

		if ($this->emailMode && !count($table))
			return '';

		return $c;
	}
}



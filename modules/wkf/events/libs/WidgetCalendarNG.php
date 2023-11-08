<?php

namespace wkf\events\libs;

use \Shipard\UI\Core\UIUtils;
use Shipard\Utils\Utils;


/**
 * class WidgetCalendarNG
 */
class WidgetCalendarNG extends \Shipard\UI\Core\UIWidgetBoard
{
	var $mobileMode;

	var $calParams;
	var $calParamsValues;
	var $viewType;
	var $today;
	var $defaultViewType = 'month';

	var $userCals = NULL;
	var $calendars;
	var $tooltips = [];

	var $yearMin = 0;
	var $yearMax = 0;

	var $year = 0;
	var $month = 0;
	var $weekDate = '';

	var $enableAdd = 0;

	public function init ()
	{
		//$this->createTabs();
		/** @var \wkf\events\TableCals */
		$tableCals = $this->app()->table('wkf.events.cals');
		$this->userCals = $tableCals->usersCals();

		foreach ($this->userCals as $cal)
		{
			if ($cal['accessLevel'] === 2)
			{
				$this->enableAdd = 1;
				break;
			}
		}

		$this->today = new \DateTime();
		$this->yearMin = intval($this->today->format('Y')) - 1;
		$this->yearMax = $this->yearMin + 4;

		parent::init();
	}

	public function createContent ()
	{
		$swipeDir = intval($this->requestParams['swipe'] ?? 0);
		$this->viewType = $this->requestParams['viewType'] ?? $this->defaultViewType;
		if ($this->viewType === 'month')
		{
			$this->year = intval($this->requestParams['activeYear'] ?? $this->today->format('Y'));
			$this->month = intval($this->requestParams['activeMonth'] ?? $this->today->format('m'));

			if ($swipeDir === self::swpLeft)
			{
				$this->month++;
				if ($this->month === 13)
				{
					if ($this->year < $this->yearMax)
					{
						$this->month = 1;
						$this->year++;
					}
					else
						$this->month = 12;
				}
				$this->requestParams['activeMonth'] = $this->month;
				$this->requestParams['activeYear'] = $this->year;
			}
			elseif ($swipeDir === self::swpRight)
			{
				$this->month--;
				if ($this->month === 0)
				{
					if ($this->year > $this->yearMin)
					{
						$this->month = 12;
						$this->year--;
					}
					else
						$this->month = 1;
				}
				$this->requestParams['activeMonth'] = $this->month;
				$this->requestParams['activeYear'] = $this->year;
			}
		}
		else
		if ($this->viewType === 'year')
		{
			$this->year = intval($this->requestParams['activeYear'] ?? $this->today->format('Y'));
			if ($swipeDir === self::swpLeft)
			{
				if ($this->year < $this->yearMax)
					$this->year++;
				$this->requestParams['activeYear'] = $this->year;
			}
			elseif ($swipeDir === self::swpRight)
			{
				if ($this->year > $this->yearMin)
					$this->year--;
				$this->requestParams['activeYear'] = $this->year;
			}
		}
		else
		if ($this->viewType === 'week')
		{
			$this->year = intval($this->requestParams['activeYear'] ?? $this->today->format('Y'));
			if (isset($this->requestParams['activeWeek']))
				$this->weekDate = $this->requestParams['activeWeek'];
			else
			{
				$weekDate = Utils::today();
				$weekDate->modify(('Monday' === $weekDate->format('l')) ? 'monday this week' : 'last monday');
				$this->weekDate = $weekDate->format('Y-m-d');
			}
			$wd = Utils::createDateTime($this->weekDate);

			if ($this->year !== intval($wd->format('Y')))
			{
				$wwd = $this->year.$wd->format('-m-d');
				$weekDate = new \DateTime($wwd);
				$weekDate->modify(('Monday' === $weekDate->format('l')) ? 'monday this week' : 'last monday');
				$this->weekDate = $weekDate->format('Y-m-d');
			}

			if ($swipeDir === self::swpLeft)
			{
				$wd->modify('+7 days');
				$this->weekDate = $wd->format('Y-m-d');
				$this->year = intval($wd->format('Y'));
			}
			elseif ($swipeDir === self::swpRight)
			{
				$wd->modify('-7 days');
				$this->weekDate = $wd->format('Y-m-d');
				$this->year = intval($wd->format('Y'));
			}

			$this->requestParams['activeYear'] = $this->year;
			$this->requestParams['activeWeek'] = $this->weekDate;
		}

		$this->createContent_Toolbar();
		$this->panelStyle = self::psFixed;


		$this->calendars = $this->app()->cfgItem('wkf.events.cals', NULL);

		if (count($this->calendars) !== 0)
		{
			$chbxs = [];
			forEach ($this->calendars as $enumNdx => $r)
			{
				$cal = $this->calendars[$enumNdx] ?? NULL;
				$cssColor = ' border-left: 10px solid '.$cal['colorbg'].';';
				$chbxs[$enumNdx] = [
					'title' => $r['sn'], 'id' => $enumNdx,
					'css' => 'display: block;   text-align: left; background-color: transparent; border-radius: 0; margin-top: 2px;'.$cssColor,
				];
			}

			$this->addParam ('checkboxes', 'query.cals', ['items' => $chbxs, 'title' => 'Kalendáře', 'place' => 'panel']);
		}

		if (1)
		{
			$calendar = new \lib\wkf\Calendar($this->app);

			$qv = utils::queryValues();
			if (isset($qv['cals']))
				$calendar->enabledCalendars = array_keys($qv['cals']);

			if ($this->viewType === 'month')
			{
				$calendar->setMonthView($this->year, $this->month);
			}
			else
			if ($this->viewType === 'year')
			{
				$calendar->setYearView($this->year);
			}
			else
			if ($this->viewType === 'week')
			{
				$calendar->setWeekView($this->year, $this->weekDate);
			}

			$calendar->widgetId = $this->widgetId;
			$calendar->init();
			$calendar->loadEvents();

			$calCode = $this->createCalendarCode ($calendar);
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $calCode]);

			return;
		}

		$this->addContent (['type' => 'line', 'line' => ['text' => 'Pokus 123: '.$this->activeTopTab]]);
	}

	public function createContent_Toolbar ()
	{
		$this->calParams = new \E10\Params ($this->app);
		$viewTypes = ['year' => 'Rok', 'month' => 'Měsíc', 'week' => 'Týden', /*'agenda' => 'Agenda'*/];
		$this->calParams->addParam('switch', 'viewType', ['___title' => 'Pohled', 'switch' => $viewTypes, 'radioBtn' => 1, 'defaultValue' => $this->viewType]);
		$this->addParamMonth ();
		$this->addParamWeek ();
		$this->addParamYear ();
		$this->calParams->detectValues ($this->requestParams);

		$this->calParamsValues = $this->calParams->getParams();
	}


	function createCodeToolbar ()
	{
		$c = '';

		$c .= "<div class='shp-wb-toolbar'>";

		$btns = [];

		if ($this->userCals && count($this->userCals) && $this->enableAdd)
		{
			$addButton = [
				'action' => 'newform', 'data-table' => 'wkf.events.events', 'icon' => 'system/actionAdd',
				'text' => '', 'type' => 'button', 'actionClass' => 'btn shp-widget-action',
				'class' => 'e10-param-addButton', 'btnClass' => 'btn-success',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId,
				'data-action-param-table' => 'wkf.events.events'
				//'data-addParams' => $addParams,
			];
			$btns[] = $addButton;
		}

		if (count($btns))
			$c .= $this->app()->ui()->composeTextLine($btns);

		//$c .= "<span class='pull-right'>";
		$c .= $this->calParams->createParamCode('viewType');
		$c .= '&nbsp;';
		//$c .= '</span>';

		$c .= $this->calParams->createParamCode('activeMonth');
		$c .= $this->calParams->createParamCode('activeYear');
		$c .= $this->calParams->createParamCode('activeWeek');

		$c .= '</div>';

		return $c;
	}

	public function addParamMonth ()
	{
		$viewType = $this->requestParams['viewType'] ?? $this->defaultViewType;
		if ($viewType !== 'month')
			return;

		$months = [1 => 'leden',2 => 'únor',3 => 'březen',4 => 'duben',5 => 'květen',6 => 'červen',
								7 => 'červenec',8 => 'srpen',9 => 'září',10 => 'říjen',11 => 'listopad',12 => 'prosinec'];
		$this->calParams->addParam('switch', 'activeMonth', ['switch' => $months, 'defaultValue' => $this->month]);
	}

	public function addParamYear ()
	{
		$viewType = $this->requestParams['viewType'] ?? $this->defaultViewType;
		if ($viewType !== 'year' && $viewType !== 'month' && $viewType !== 'week')
			return;

		$years = [];
		for ($y = $this->yearMin; $y <= $this->yearMax; $y++)
			$years[$y] = strval ($y);
		$this->calParams->addParam('switch', 'activeYear', ['switch' => $years, 'defaultValue' => $this->year]);
	}

	public function addParamWeek ()
	{
		$viewType = $this->requestParams['viewType'] ?? $this->defaultViewType;
		if ($viewType !== 'week')
			return;

		$weekYear = $this->year;
		$thisWeekNumber = intval(Utils::createDateTime($this->weekDate)->format ('W'));
		$thisWeekDate = '';

		$weeks = [];
		for ($y = 1; $y < 54; $y++)
		{
			$thisWeekYear = intval(Utils::weekDate ($y, $weekYear, 1, 'Y'));
			if ($thisWeekYear > $weekYear)
				break;
			$weekName = $y . ' (' . Utils::weekDate ($y, $weekYear, 1, 'd.m') . ' - ' . Utils::weekDate ($y, $weekYear, 7, 'd.m') . ')';
			$weekNumber = Utils::weekDate ($y, $weekYear);
			if ($thisWeekNumber === $y)
				$thisWeekDate = $weekNumber;
			$weeks[$weekNumber] = $weekName;
		}
		$this->calParams->addParam('switch', 'activeWeek', ['switch' => $weeks, 'defaultValue' => $thisWeekDate]);
	}

	public function renderMonth($calendar, $year, $month, $style, $params = NULL)
	{
		$c = '';

		$firstDay = utils::createDateTime("$year-$month-01");
		$activeDate = clone $firstDay->modify(('Monday' === $firstDay->format('l')) ? 'monday this week' : 'last monday');

		if ($style === 'big' || $month < 4 || ($params && isset($params['headerWithDays'])))
		{
			$c .= "<thead><tr>";
			$c .= "<th class='month'>";
			$c .= '</th>';
			for ($weekDay = 0; $weekDay < 7; $weekDay++)
			{
				$c .= "<th>";
				$c .= utils::$dayShortcuts[$weekDay];
				$c .= '</th>';
			}
			$c .= '</tr></thead>';
		}

		$cntWeeks = 0;

		while (1)
		{
			$cntWeeks++;
			$c .= "<tr>";

			$thisWeek = intval($activeDate->format('W'));
			$title = strval($thisWeek);
			$c .= "<td class='week'>";
			$c .= $title;
			$c .= '</td>';

			for ($weekDay = 0; $weekDay < 7; $weekDay++)
			{
				$dayId = $activeDate->format('Y-m-d');
				$thisDay = intval($activeDate->format('d'));
				$thisMonth = intval($activeDate->format('m'));
				$title = strval($thisDay);
				if ($thisMonth != $month)
					//$title = strval($thisDay) . '.' . strval($thisMonth) . '.';
					$title = strval($thisDay);

				if ($params && isset($params['enabledDays']) &&
						(!isset($params['enabledDays'][$thisMonth]) || !in_array($thisDay, $params['enabledDays'][$thisMonth])))
				{
					$c .= "<td class='day inactive e10-warning2'>$title</td>";
				}
				else
				{
					$css = '';
					$class = '';
					if ($thisMonth != $month)
					{
						$class .= ' inactive';
					}
					if ($style === 'small' && isset ($calendar->events[$dayId]))
						$class .= ' tooltips';
					if ($dayId == $this->today->format('Y-m-d'))
						$class .= ' today';

					$dayTitle = utils::es($thisDay . '. ' . utils::$monthNamesForDate[$thisMonth - 1]);
					$c .= "<td class='day e10-param-btn{$class}' data-value='$dayId' data-date='$dayId' data-title='$dayTitle' tabindex='1' style='$css'>";

					if ($style === 'small')
						$c .= "<span style='float:right; padding-left: 2px;'>".$title."</span>";
					else
					{
						$c .= "<span class='title'>" . utils::es($title) . '</span>';
					}
					$c .= $this->renderEvents($calendar, $dayId, $style);

					$c .= '</td>';
				}
				$activeDate->modify('+1 day');
			}
			$c .= '</tr>';

			if ($activeDate->format('m') != $month)
			{
				break;
			}
		}

		if ($style === 'small' && (!$params || !isset($params['disableMonthName'])))
		{
			$c .= "<tr class='monthName'><td></td><td colspan='7'>";
			$c .= utils::$monthNames[$month-1];
			$c .= '</td></tr>';
		}

		$code = "<table class='shp-cal-$style wks-$cntWeeks'>";
		$code .= $c;
		$code .= '</table>';

		return $code;
	}

	public function renderYear($calendar, $year)
	{
		$c = '';
		$month = 1;

		$c .= "<table class='shp-cal-year'>";
		for ($q = 1; $q <= 4; $q++)
		{
			$c .= "<tr style='height: 25%;'>";
			for ($m = 1; $m <= 3; $m++)
			{
				$c .= "<td>";
				$c .= $this->renderMonth($calendar, $year, $month, 'small');
				$c .= '</td>';

				$month++;
			}
			$c .= '</tr>';
		}

		$c .= '</table>';

		return $c;
	}

	public function renderWeek($calendar, $year, $weekDate, $style, $params = NULL)
	{
		$tdd = $this->today->format('Y-m-d');
		$c = '';

		$c .= "<table class='shp-cal-$style'>";

		$activeDate = utils::createDateTime($weekDate);
		$c .= "<thead><tr>";
		for ($weekDay = 0; $weekDay < 7; $weekDay++)
		{
			$dayId = $activeDate->format('Y-m-d');
			$class = '';
			if ($tdd === $dayId)
				$class = 'today';
			$c .= "<th class='$class'>";
			$c .= utils::$dayShortcuts[$weekDay].' <small>'.$activeDate->format('d.m.').'</small>';
			$c .= '</th>';
			$activeDate->modify('+1 day');
		}
		$c .= '</tr></thead>';

		$c .= "<tr>";

		$activeDate = utils::createDateTime($weekDate);
		for ($weekDay = 0; $weekDay < 7; $weekDay++)
		{
			$dayId = $activeDate->format('Y-m-d');

			$class = '';
			if ($tdd === $dayId)
				$class = 'today';

			$c .= "<td class='wk {$class}'>";
			$c .= $this->renderEvents($calendar, $dayId, $style);
			$c .= '</td>';

			$activeDate->modify('+1 day');
		}
		$c .= '</tr>';

		$c .= '</table>';

		return $c;
	}

	public function createCalendarCode ($calendar)
	{
		$c = '';
		if ($this->viewType === 'month')
		{
			$c .= $this->renderMonth($calendar, $this->year, $this->month, 'big');
		}
		elseif ($this->viewType === 'week')
		{
			$c .= $this->renderWeek($calendar, $this->year, $calendar->weekDate, 'big');
		}
		elseif ($this->viewType === 'year')
		{
			$c .= $this->renderYear($calendar, $this->year);

			$c .= "<script>\n";
			$c .= "var calTooltips = ".json_encode($this->tooltips).";\n";
			$c .= "function calTooltip (dayId) {return 'nazdar!!!'};\n\n";
			//$c .= "$('#e10dashboardWidget table.shp-cal-small>tbody>tr>td.day.tooltips').popover({content:function(){return calTooltips[$(this).attr('data-date')];}, html: true, trigger: 'focus', delay: {'show': 0, 'hide': 500}, container: 'body', placement: 'auto', viewport:'#e10dashboardWidget'});";

			$c .= "
			function setCalPopovers() {\n
				let tooltips = document.querySelectorAll('table.shp-cal-small>tbody>tr>td.day.tooltips');\n
				for(let i = 0; i < tooltips.length; i++) {\n
					let tooltip = new bootstrap.Popover(tooltips[i],
						{
							content: calTooltips[tooltips[i].getAttribute('data-date')],
							sanitize: false, html: true, trigger: 'focus', delay: {'show': 0, 'hide': 100}, container: 'div.shp-widget-board', placement: 'auto', XXXviewport:'#e10dashboardWidget'
						}
					);\n
				}\n
			}\n
			setCalPopovers();
			";

			$c .= "</script>\n";
		}
		return $c;
	}

	public function renderEvents ($calendar, $dayId, $style)
	{
		if ($style === 'big')
			return $this->renderEventsBig($calendar, $dayId);

		return $this->renderEventsSmall($calendar, $dayId);
	}

	public function renderEventsBig ($calendar, $dayId)
	{
		$date = utils::createDateTime($dayId);
		$c = "<div class='events' data-style='display: none;'>";
		if (isset($calendar->events[$dayId]))
		{
			foreach ($calendar->events[$dayId] as $e)
			{
				$cal = $calendar->calendars[$e['calendar']] ?? NULL;
				$pfx = '';

				/*
				if ($e['timeBegin'] !== '')
					$pfx = $e['timeBegin'];

				if ($e['timeEnd'] !== '')
					$pfx .= ' - '.$e['timeEnd'];
				*/

				$event = [
					'text' => $e['subject'], 'actionClass' => 'e10-suffix-block tag tag-event shp-widget-action ' . $e['docStateClass'], // 'icon' => $e['icon'],
					'type' => 'action', 'action' => 'edit', 'pk' => $e['ndx'], 'table' => 'wkf.events.events',
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId,
					'element' => 'span',
					'data-action-param-table' => 'wkf.events.events',
					'data-action-param-pk' => strval($e['ndx']),
				];

				//if ($e['placeDesc'] !== '')
				//	$event['suffix'] = '  '.$e['placeDesc'];

				if ($cal)
					$event['css'] = 'background-color: '.$cal['colorbg'].';';

				$c .= "<div>";
				$c .= $this->app()->ui()->composeTextLine($event);
				$c .= "</div>";
			}
		}
		$c .= '</div>';

		return $c;
	}

	public function renderEventsSmall ($calendar, $dayId)
	{
		$events = [];
		$badges = [];
		if (isset($calendar->events[$dayId]))
		{
			foreach ($calendar->events[$dayId] as $e)
			{
				$calNdx = intval($e['calendar']);
				$cal = $calendar->calendars[$e['calendar']] ?? NULL;

				$pfx = '';

				if ($e['timeBegin'] !== '')
					$pfx = $e['timeBegin'];

				if ($e['timeEnd'] !== '')
					$pfx .= ' - '.$e['timeEnd'];

				$event = [
						'text' => $pfx.' '.$e['subject'], 'actionClass' => 'tag tag-event shp-widget-action ' . $e['docStateClass'],
						'type' => 'action', 'action' => 'edit', 'pk' => $e['ndx'], 'table' => 'wkf.events.events',
						'element' => 'span',
						'data-action-param-table' => 'wkf.events.events',
						'data-action-param-pk' => strval($e['ndx']),
						'data-action' => 'edit',
				];

				$css = '';
				if ($cal)
				{
					$css = " style='color: ".$cal['colorbg']."; height: 10px; margin: 0; line-height: 1;'";
					$event['css'] = 'background-color: '.$cal['colorbg'].';';
				}

				$events [] = $event;

				if (!isset($badges[$calNdx]))
				{
					$badges[$calNdx] = ['count' => 1, 'css' => $css, 'dsc' => $e['docStateClass']];
				}
				else
					$badges[$calNdx]['count']++;
			}
		}

		$c = '';
		$c = "<div style='display: inline-block;'>";
		foreach ($badges as $calNdx => $badge)
		{
			$c .= "<span {$badge['css']}>"."●"."</span>";
		}
		$c .= '</div>';

		$this->tooltips[$dayId] = '';
		foreach ($events as $e)
			$this->tooltips[$dayId] .= $this->app()->ui()->composeTextLine($e);

		return $c;
	}
}

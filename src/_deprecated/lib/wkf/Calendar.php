<?php

namespace lib\Wkf;

use \e10\utils, \e10\uiutils, \e10\Utility, e10pro\wkf\TableMessages;


/**
 * Class Calendar
 * @package lib\Wkf
 */
class Calendar extends Utility
{
	var $year;
	var $month;
	var $style;
	var $today;
	var $weekDate;
	var $events = [];
	var $tooltips = [];
	var $calendars;

	/** @var \wkf\events\TableEvents */
	var $tableEvents;

	var $firstEventDate = NULL;
	var $lastEventDate = NULL;

	var $widgetId = '';

	var $enabledCalendars = NULL;

	public function init()
	{
		$this->tableEvents = $this->app->table ('wkf.events.events');
		$this->today = utils::today();
		$this->calendars = $this->app()->cfgItem('wkf.events.cals', NULL);

	}

	public function loadEvents ()
	{
		$thisUserId = intval($this->app->userNdx());
		$ug = $this->app->userGroups ();

		$q [] = 'SELECT events.*, persons.fullName as authorFullName ';
		array_push ($q, ' FROM [wkf_events_events] AS [events]');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON events.author = persons.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->enabledCalendars)
			array_push ($q, ' AND (events.[calendar] IN %in)', $this->enabledCalendars);

		// -- type
		array_push ($q, ' AND (events.[docState] != %i)', 9800);

		array_push ($q, ' AND (');
		array_push ($q, ' events.[dateBegin] <= %s', $this->lastEventDate);
		array_push ($q, ' OR (events.[dateEnd] >= %s OR events.[dateEnd] IS NULL)', $this->firstEventDate);
		array_push ($q, ')');

		array_push ($q, ' ORDER BY [dateTimeBegin]');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$docState = $this->tableEvents->getDocumentState ($r);
			$docStateClass = $this->tableEvents->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');

			$newEvent = [
				'ndx' => $r['ndx'], 'icon' => $this->tableEvents->tableIcon ($r, 1),
				'subject' => $r['title'], 'calendar' => $r['calendar'], 'placeDesc' => $r['placeDesc'],
				'dateBegin' => $r['dateBegin'], 'timeBegin' => $r['timeBegin'], 'dateTimeBegin' => $r['dateTimeBegin'],
				'dateEnd' => $r['dateEnd'], 'timeEnd' => $r['timeEnd'], 'dateTimeEnd' => $r['dateTimeEnd'],
				'docState' => $r['docState'], 'docStateClass' => $docStateClass
			];

			if ($r['multiDays'])
			{
				$firstDate = utils::createDateTime($r['dateBegin']->format ('Y-m-d'));
				$endDate = utils::createDateTime($r['dateEnd']->format ('Y-m-d'));
				$allDay = 0;
				while (1)
				{
					if ($firstDate > $endDate)
						break;
					$dayId = $firstDate->format('Y-m-d');
					$newEvent['allDay'] = $allDay;
					$this->events[$dayId][] = $newEvent;

					$firstDate->modify('+1 day');
					$allDay = 1;
				}
			}
			else
			{
				$deadline = NULL;
				if ($r['dateBegin'])
					$deadline = $r['dateBegin'];
				if (!$deadline)
					continue;
				$dayId = $deadline->format('Y-m-d');
				$this->events[$dayId][] = $newEvent;
			}
		}
	}

	public function setMonthView($year, $month)
	{
		$this->style = 'month';
		$this->year = $year;
		$this->month = $month;
		$this->firstEventDate = $year.'-'.$month.'-01';
		$this->lastEventDate = date("Y-m-t", strtotime($this->firstEventDate));
	}

	public function setYearView($year)
	{
		$this->style = 'year';
		$this->year = $year;
		$this->firstEventDate = $year.'-01-01';
		$this->lastEventDate = $year.'-12-31';
	}

	public function setWeekView($year, $weekDate)
	{
		$this->style = 'week';
		$this->year = $year;
		$this->weekDate = $weekDate;
		$this->firstEventDate = $weekDate;
		$d = new \DateTime ($weekDate);
		$d->modify('+7 day');
		$this->lastEventDate = $d->format ('Y-m-d');
	}

	public function renderMonth($year, $month, $style, $params = NULL)
	{
		$c = '';

		$firstDay = utils::createDateTime("$year-$month-01");
		$activeDate = clone $firstDay->modify(('Monday' === $firstDay->format('l')) ? 'monday this week' : 'last monday');

		$c .= "<table class='e10-cal-$style'>";
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

		while (1)
		{
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
						$class .= ' inactive e10-off e10-small';
						$css .= ' opacity: .6;';
					}
					if ($style === 'small' && isset ($this->events[$dayId]))
						$class .= ' tooltips';
					if ($dayId == $this->today->format('Y-m-d'))
						$css .= ' background-color: #00A01030;';

					$dayTitle = utils::es($thisDay . '. ' . utils::$monthNamesForDate[$thisMonth - 1]);
					$c .= "<td class='day e10-param-btn{$class}' data-value='$dayId' data-date='$dayId' data-title='$dayTitle' tabindex='1' style='padding: 2px; line-height: 1;$css'>";

					if ($style === 'small')
						$c .= "<span style='float:right; padding-left: 2px;'>".$title."</span>";
					else
					{
						$c .= "<span class='title'>" . utils::es($title) . '</span>';
					}
					$c .= $this->renderEvents($dayId, $style);

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

		$c .= '</table>';

		return $c;
	}

	public function renderYear($year)
	{
		$c = '';
		$month = 1;

		$c .= "<table class='e10-cal-year'>";
		for ($q = 1; $q <= 4; $q++)
		{
			$c .= "<tr style='height: 25%;'>";
			for ($m = 1; $m <= 3; $m++)
			{
				$c .= "<td>";
				$c .= $this->renderMonth($year, $month, 'small');
				$c .= '</td>';

				$month++;
			}
			$c .= '</tr>';
		}

		$c .= '</table>';

		return $c;
	}

	public function renderWeek($year, $weekDate, $style, $params = NULL)
	{
		$c = '';

		$c .= "<table class='e10-cal-$style'>";

		$activeDate = utils::createDateTime($weekDate);
		$c .= "<thead><tr>";
		for ($weekDay = 0; $weekDay < 7; $weekDay++)
		{
			$c .= '<th>';
			$c .= utils::$dayShortcuts[$weekDay].' <small>'.$activeDate->format('d.m').'</small>';
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

			$c .= "<td class='wk {$class}'>";
			$c .= $this->renderEvents($dayId, $style);
			$c .= '</td>';

			$activeDate->modify('+1 day');
		}
		$c .= '</tr>';

		$c .= '</table>';

		return $c;
	}

	public function renderEvents ($dayId, $style)
	{
		if ($style === 'big')
			return $this->renderEventsBig($dayId);

		return $this->renderEventsSmall($dayId);
	}

	public function renderEventsBig ($dayId)
	{
		$date = utils::createDateTime($dayId);
		$c = "<div class='events' style='display: none;'>";
		if (isset($this->events[$dayId]))
		{
			foreach ($this->events[$dayId] as $e)
			{
				$cal = $this->calendars[$e['calendar']] ?? NULL;
				$pfx = '';

				if ($e['timeBegin'] !== '')
					$pfx = $e['timeBegin'];

				if ($e['timeEnd'] !== '')
					$pfx .= ' - '.$e['timeEnd'];

				$event = [
					'text' => $pfx.' '.$e['subject'], 'class' => 'e10-suffix-block tag tag-event ' . $e['docStateClass'], 'icon' => $e['icon'],
					'docAction' => 'edit', 'pk' => $e['ndx'], 'table' => 'wkf.events.events',
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId,
				];

				if ($e['placeDesc'] !== '')
					$event['suffix'] = '  '.$e['placeDesc'];

				if ($cal)
					$event['css'] = 'border-left: 10px solid '.$cal['colorbg'].';';

				$c .= $this->app()->ui()->composeTextLine($event);
			}
		}
		$c .= '</div>';

		return $c;
	}

	public function renderEventsSmall ($dayId)
	{
		$events = [];
		$badges = [];
		if (isset($this->events[$dayId]))
		{
			foreach ($this->events[$dayId] as $e)
			{
				$calNdx = intval($e['calendar']);
				$cal = $this->calendars[$e['calendar']] ?? NULL;

				$pfx = '';

				if ($e['timeBegin'] !== '')
					$pfx = $e['timeBegin'];

				if ($e['timeEnd'] !== '')
					$pfx .= ' - '.$e['timeEnd'];

				$event = [
						'text' => $pfx.' '.$e['subject'], 'class' => 'tag tag-event ' . $e['docStateClass'], 'icon' => $e['icon'],
						'docAction' => 'edit', 'pk' => $e['ndx'], 'table' => 'wkf.events.events',
						'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId,
				];

				$css = '';
				if ($cal)
				{
					$css = " style='color: ".$cal['colorbg']."; height: 10px; margin: 0; line-height: 1;'";
					$event['css'] = 'border-left: 10px solid '.$cal['colorbg'].';';
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

	public function createCode ()
	{
		$c = '';
		if ($this->style === 'month')
		{
			$c .= "<div class='padd5'>";
			$c .= $this->renderMonth($this->year, $this->month, 'big');
			$c .= '</div>';

			$c .= "<script>
				var maxh = $('#e10dashboardWidget div.e10-widget-content').innerHeight() - $('#e10-cal-tlbr').height();
				console.log (maxh);
				var hh = (maxh - 70) / 5;
				$('#e10dashboardWidget').find ('table.e10-cal-big tbody>tr>td.day').each (function () {
			      var oo = $(this);
			      $(this).height(hh);
						$(this).find('div.events').width(oo.innerWidth()-4).height(hh - 34).show();
				});
			</script>";
		}
		else
		if ($this->style === 'week')
		{
			$c .= "<div class='padd5'>";
			$c .= $this->renderWeek($this->year, $this->weekDate, 'big');
			$c .= '</div>';

			$c .= "<script>
			$('#e10dashboardWidget').find ('table.e10-cal-big tbody>tr>td.wk>div.events').each (function () {
					var oo = $(this).parent();
					$(this).width(oo.width() - 1).show().height(oo.innerHeight()-10);
			});
		</script>";
		}
		else
		if ($this->style === 'year')
		{
			$c .= "<div class='e10-cal-big padd5' style='height: 100%;'>";
			$c .= $this->renderYear($this->year);
			$c .= '</div>';

			$c .= "<script>\n";
			$c .= "var calTooltips = ".json_encode($this->tooltips).";\n";
			$c .= "function calTooltip (dayId) {return 'nazdar!!!'};\n";
			$c .= "$('#e10dashboardWidget table.e10-cal-small>tbody>tr>td.day.tooltips').popover({content:function(){return calTooltips[$(this).attr('data-date')];}, html: true, trigger: 'focus', delay: {'show': 0, 'hide': 500}, container: 'body', placement: 'auto', viewport:'#e10dashboardWidget'});";
			$c .= "</script>\n";
		}
		return $c;
	}
}

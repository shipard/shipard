<?php

namespace lib\Wkf;

require_once __APP_DIR__ . '/e10-modules/e10/persons/tables/persons.php';

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
	var $tableMessages;

	var $firstEventDate = NULL;
	var $lastEventDate = NULL;

	public function init()
	{
		$this->tableMessages = $this->app->table ('e10pro.wkf.messages');
		$this->today = utils::today();
	}

	public function loadEvents ()
	{
		$thisUserId = intval($this->app->user()->data ('id'));

		$q [] = 'SELECT messages.*, persons.fullName as authorFullName, projects.fullName as projectFullName, parts.id as projectPartId, parts.deadline as partDeadline FROM [e10pro_wkf_messages] as messages';
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON messages.author = persons.ndx');
		array_push ($q, ' LEFT JOIN e10pro_wkf_projects as projects ON messages.project = projects.ndx');
		array_push ($q, ' LEFT JOIN e10pro_wkf_projectsParts as parts ON messages.projectPart = parts.ndx');
		array_push ($q, ' WHERE 1');

		// -- type
		array_push ($q, " AND (messages.[msgType] IN %in)", [TableMessages::mtIssue, TableMessages::mtEvent, TableMessages::mtActivity]);
		array_push ($q, " AND (messages.[docState] != 9800)");

		// -- only my events
		array_push ($q, ' AND (');
		array_push ($q, " EXISTS (
												SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks
												where messages.ndx = srcRecId AND srcTableId = %s AND dstTableId = %s AND docLinks.dstRecId = %i)",
			'e10pro.wkf.messages', 'e10.persons.persons', $thisUserId);

		$ug = $this->app->userGroups ();
		if (count ($ug) !== 0)
			array_push ($q, ' OR '.
				' EXISTS (
													SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks
													where messages.ndx = srcRecId AND srcTableId = %s AND dstTableId = %s AND docLinks.dstRecId IN (%sql))',
				'e10pro.wkf.messages', 'e10.persons.groups', implode (', ', $ug));

		array_push ($q, ' OR ');
		array_push ($q, " (messages.author = %i)", $thisUserId);
		array_push ($q, ' OR ');

		array_push ($q, '(');
		array_push ($q, " EXISTS (
											SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks
											where messages.project = srcRecId AND srcTableId = %s AND dstTableId = %s AND docLinks.dstRecId = %i)",
			'e10pro.wkf.projects', 'e10.persons.persons', $thisUserId);
		if (count ($ug) !== 0)
			array_push ($q, ' OR '.
				' EXISTS (
						SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks
						where messages.project = srcRecId AND srcTableId = %s AND dstTableId = %s AND docLinks.dstRecId IN (%sql))',
				'e10pro.wkf.projects', 'e10.persons.groups', implode (', ', $ug));
		array_push ($q, ')');

		array_push ($q, ')');

		array_push ($q, ' AND (');
		array_push ($q, ' messages.[dateBegin] <= %s', $this->lastEventDate);
		array_push ($q, ' OR (messages.[dateEnd] >= %s OR messages.[dateEnd] IS NULL)', $this->firstEventDate);
		array_push ($q, ')');

		array_push ($q, ' ORDER BY [date], [dateBegin]');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$docState = $this->tableMessages->getDocumentState ($r);
			$docStateClass = $this->tableMessages->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');

			$newEvent = [
				'ndx' => $r['ndx'], 'msgType' => $r['msgType'], 'msgKind' => $r['msgKind'], 'icon' => $this->tableMessages->tableIcon ($r, 1),
				'subject' => $r['subject'], 'dateBegin' => $r['dateBegin'], 'dateEnd' => $r['dateEnd'],
				'docState' => $r['docState'], 'docStateClass' => $docStateClass];

			if ($r['type'] === 'event' && !utils::dateIsBlank($r['dateBegin']))
			{
				$firstDate = utils::createDateTime($r['dateBegin']->format ('Y-m-d'));
				$allDay = 0;
				while (1)
				{
					$dayId = $firstDate->format('Y-m-d');
					$newEvent['allDay'] = $allDay;
					$this->events[$dayId][] = $newEvent;

					$firstDate->modify('+1 day');
					if ($firstDate > $r['dateEnd'])
						break;
					$allDay = 1;
				}
			}
			else
			{
				$deadline = NULL;
				if ($r['partDeadline'])
					$deadline = $r['partDeadline'];
				if ($r['date'])
					$deadline = $r['date'];
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
					$title = strval($thisDay) . '.' . strval($thisMonth) . '.';

				if ($params && isset($params['enabledDays']) &&
						(!isset($params['enabledDays'][$thisMonth]) || !in_array($thisDay, $params['enabledDays'][$thisMonth])))
				{
					$c .= "<td class='day inactive e10-warning2'>$title</td>";
				}
				else
				{
					$class = '';
					if ($thisMonth != $month)
						$class .= ' inactive';
					if ($style === 'small' && isset ($this->events[$dayId]))
						$class .= ' tooltips';

					$dayTitle = utils::es($thisDay . '. ' . utils::$monthNamesForDate[$thisMonth - 1]);
					$c .= "<td class='day e10-param-btn{$class}' data-value='$dayId' data-date='$dayId' data-title='$dayTitle' tabindex='1'>";

					if ($style === 'small')
						$c .= $title;
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
				$event = [
						'text' => $e['subject'], 'class' => 'tag tag-event ' . $e['docStateClass'], 'icon' => $e['icon'],
						'docAction' => 'edit', 'pk' => $e['ndx'], 'table' => 'e10pro.wkf.messages'];

				$pfx = utils::dateFromTo($e['dateBegin'], $e['dateEnd'], $date);
				if ($pfx !== '')
					$event['prefix'] = $pfx;

				$c .= $this->app()->ui()->composeTextLine($event);
			}
		}
		$c .= '</div>';

		return $c;
	}

	public function renderEventsSmall ($dayId)
	{
		$events = [];
		$counts = [];
		if (isset($this->events[$dayId]))
		{
			foreach ($this->events[$dayId] as $e)
			{
				$event = [
						'text' => $e['subject'], 'class' => 'tag tag-event ' . $e['docStateClass'], 'icon' => $e['icon'],
						'docAction' => 'edit', 'pk' => $e['ndx'], 'table' => 'e10pro.wkf.messages'];
				if (isset($e['dateBegin']))
					$event['prefix'] = utils::datef($e['dateBegin'], '%T');
				$events [] = $event;

				if (!isset($counts[$e['docStateClass']]))
					$counts[$e['docStateClass']] = 0;
				$counts[$e['docStateClass']]++;
			}
		}
		$c = "<div class='events'><span class='list'>";
		foreach ($counts as $dsc => $cnt)
			$c .= "<span class='tag $dsc'>$cnt</span>";
		$c .= '</span></div>';

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
			$c .= "$('#e10dashboardWidget table.e10-cal-small>tbody>tr>td.day.tooltips').popover({content:function(){return calTooltips[$(this).attr('data-date')];}, html: true, trigger: 'focus', delay: {'show': 0, 'hide': 100}, container: 'body', placement: 'auto', viewport:'#e10dashboardWidget'});";
			$c .= "</script>\n";
		}
		return $c;
	}
}

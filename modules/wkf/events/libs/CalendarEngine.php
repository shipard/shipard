<?php

namespace wkf\events\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;



/**
 * class CalendarEngine
 */
class CalendarEngine extends Utility
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

  var \lib\core\texts\Renderer $textRenderer;


	public function init()
	{
		$this->tableEvents = $this->app->table ('wkf.events.events');
		$this->today = Utils::today();
		$this->calendars = $this->app()->cfgItem('wkf.events.cals', NULL);

    $this->textRenderer = new \lib\core\texts\Renderer($this->app());
    $this->textRenderer->setFirstHeaderSize (3);
	}

	public function loadDailyEvents ()
	{
		$q [] = 'SELECT events.*, persons.fullName as authorFullName ';
		array_push ($q, ' FROM [wkf_events_events] AS [events]');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON events.author = persons.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->enabledCalendars)
			array_push ($q, ' AND (events.[calendar] IN %in)', $this->enabledCalendars);

		// -- type
		array_push ($q, ' AND (events.[docState] != %i)', 9800);

    array_push ($q, ' AND events.[dateBegin] >= %s', $this->firstEventDate);
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
				'subject' => $r['title'], 'text' => $r['text'],
        'calendar' => $r['calendar'], 'placeDesc' => $r['placeDesc'],
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

    array_push ($q, ' AND events.[dateBegin] >= %s', $this->firstEventDate);
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
			$this->textRenderer->render($r ['text']);

			$newEvent = [
				'ndx' => $r['ndx'], 'icon' => $this->tableEvents->tableIcon ($r, 1),
				'subject' => $r['title'], 'text' => $r['text'], 'textHTML' => $this->textRenderer->code,
        'calendar' => $r['calendar'], 'placeDesc' => $r['placeDesc'],
				'dateBegin' => $r['dateBegin'], 'timeBegin' => $r['timeBegin'], 'dateTimeBegin' => $r['dateTimeBegin'],
				'dateEnd' => $r['dateEnd'], 'timeEnd' => $r['timeEnd'], 'dateTimeEnd' => $r['dateTimeEnd'],
				'docState' => $r['docState'], 'docStateClass' => $docStateClass,
        'multiDays' => $r['multiDays'],
			];

      $this->events[] = $newEvent;
		}
	}

  public function setAgendaView($fromDate)
	{
		$this->style = 'agenda';
		//$this->year = $fromDate;
		$this->firstEventDate = $fromDate;
		$d = new \DateTime ($fromDate);
		$d->modify('+1 year');
		$this->lastEventDate = $d->format ('Y-m-d');
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
}

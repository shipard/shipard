<?php

namespace lib\Wkf;
use \E10\utils, \E10\Utility;


/**
 * Class Booking
 * @package lib\Wkf
 */
class Booking extends Utility
{
	var $useContracts = 1;
	var $year;
	var $month;
	var $style;
	var $today;
	var $bookingType;
	var $bookingTypeDef;
	var $dateBegin;
	var $dateEnd;
	var $places = [];
	var $bookings = [];
	var $dayCounts;
	var $tooltips = [];

	var $widgetId;

	public function init()
	{
		if ($this->app->model()->module('e10doc.contracts.sale') === FALSE)
			$this->useContracts = 0;

		$this->today = utils::today();
		$this->loadPlaces();
		$this->loadBookings();
		$this->loadContracts();
		$this->calcDialyCounts();
	}

	public function loadBookings ()
	{
		$tableBookings = $this->app->table ('e10pro.booking.bookings');

		$q [] = 'SELECT bookings.*, places.shortName as placeName, places.bookingCapacity as placeCapacity FROM [e10pro_booking_bookings] as bookings';
		$q [] = ' LEFT JOIN e10_base_places as places ON bookings.place = places.ndx';
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND (bookings.[bookingType] = %s)', $this->bookingType);
		array_push ($q, ' AND (bookings.[docState] != 9800)');

		array_push ($q, ' AND (bookings.[dateBegin] IS NULL OR bookings.[dateBegin] <= %d)', $this->dateEnd);
		array_push ($q, ' AND (bookings.[dateEnd] IS NULL OR bookings.[dateEnd] >= %d)', $this->dateBegin);

		array_push ($q, ' ORDER BY [dateBegin]');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$dateBegin = $r['dateBegin'];
			if (!$dateBegin || $dateBegin < $this->dateBegin)
				$dateBegin = $this->dateBegin;

			$dateEnd = $r['dateEnd'];
			if (!$dateEnd || $dateEnd > $this->dateEnd)
				$dateEnd = $this->dateEnd;

			$dayId = $dateBegin->format ('Y-m-d');
			$days = utils::dateDiff($dateBegin, $dateEnd) + 1;

			$docState = $tableBookings->getDocumentState ($r);
			$docStateClass = $tableBookings->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');

			$placeCapacity = $r['placeCapacity'];
			if (!$placeCapacity)
				$placeCapacity = 1;

			$cntParts = $r['cntParts'];
			if (!$cntParts || $cntParts === 100|| $cntParts === 254)
				$cntParts = $placeCapacity;

			$newBooking = [
				'ndx' => $r['ndx'], 'subject' => $r['subject'], 'dateBegin' => $dateBegin, 'dateEnd' => $dateEnd, 'onRow' => 0, 'cntParts' => $cntParts,
				'docState' => $r['docState'], 'docStateClass' => $docStateClass, 'days' => $days, 'table' => 'e10pro.booking.bookings'];
			$this->bookings[$r['place']][$dayId][] = $newBooking;
		}
	}

	public function loadContracts ()
	{
		if (!$this->useContracts)
			return;

		$q [] = 'SELECT [rows].*, places.shortName as placeName, heads.start as headDateBegin, heads.end as headDateEnd, persons.fullName as personFullName,';
		$q [] = ' heads.ndx as headNdx, heads.[docState] AS headDocState, places.bookingCapacity as placeCapacity';
		$q [] = ' FROM [e10doc_contracts_rows] as [rows]';
		$q [] = ' LEFT JOIN e10doc_contracts_heads as heads ON [rows].contract = heads.ndx';
		$q [] = ' LEFT JOIN e10_base_places as places ON [rows].bookingPlace = places.ndx';
		$q [] = ' LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx';
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND places.[bookingType] = %s', $this->bookingType);
		array_push ($q, ' AND heads.[docState] IN %in', [4000, 8000], ' AND heads.[bookingPlaces] = 1');

		array_push ($q, ' AND (([rows].[start] IS NULL OR [rows].[start] <= %d) AND (heads.[start] IS NULL OR heads.[start] <= %d))', $this->dateEnd, $this->dateEnd);
		array_push ($q, ' AND (([rows].[end] IS NULL OR [rows].[end] >= %d) AND (heads.[end] IS NULL OR heads.[end] >= %d))', $this->dateBegin, $this->dateBegin);

		array_push ($q, ' ORDER BY heads.[start]');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$dateBegin = $r['start'];
			if (!$dateBegin)
				$dateBegin = $r['headDateBegin'];
			if (!$dateBegin || $dateBegin < $this->dateBegin)
				$dateBegin = $this->dateBegin;

			$dateEnd = $r['end'];
			if (!$dateEnd)
				$dateEnd = $r['headDateEnd'];
			if (!$dateEnd || $dateEnd > $this->dateEnd)
				$dateEnd = $this->dateEnd;

			$dayId = $dateBegin->format ('Y-m-d');
			$days = utils::dateDiff($dateBegin, $dateEnd) + 1;

			$placeCapacity = $r['placeCapacity'];
			if (!$placeCapacity)
				$placeCapacity = 1;

			$cntParts = $r['cntParts'];
			if (!$cntParts || $cntParts === 100)
				$cntParts = $placeCapacity;

			$newBooking = [
				'ndx' => $r['headNdx'], 'subject' => $r['personFullName'], 'dateBegin' => $dateBegin, 
				'dateEnd' => $dateEnd, 'onRow' => 0,'cntParts' => $cntParts,
				'docState' => $r['headDocState'],
				'days' => $days, 'table' => 'e10doc.contracts.core.heads'];
			$this->bookings[$r['bookingPlace']][$dayId][] = $newBooking;
		}
	}

	public function loadPlaces ()
	{
		$q [] = 'SELECT * from [e10_base_places] AS places';
		array_push ($q, ' WHERE [bookingType] = %s', $this->bookingType, 'AND docState = 4000');
		array_push ($q, ' ORDER BY [id], [fullName]');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$np = ['ndx' => $r['ndx'], 'id' => $r['id'], 'sn' => $r['shortName'], 'cap' => $r['bookingCapacity']];
			if ($r['bookingCapacity'] == 0)
				$np['cap'] = 1;
			$this->places [$r['ndx']] = $np;
		}
	}

	public function calcDialyCounts ()
	{
		$numDays = intval($this->dateBegin->format('t'));

		$data = [];

		foreach ($this->places as $placeNdx => $place)
		{
			for ($day = 1; $day <= $numDays; $day++)
			{
				$dayId = sprintf("%04d-%02d-%02d", $this->year, $this->month, $day);
				$data[$placeNdx][$dayId] = 0;
			}
		}

		foreach ($this->places as $placeNdx => $place)
		{
			if (!isset($this->bookings[$placeNdx]))
				continue;
			foreach ($this->bookings[$placeNdx] as &$dayBookings)
			{
				foreach ($dayBookings as &$oneBooking)
				{
					$firstDay = intval(utils::createDateTime($oneBooking['dateBegin'])->format('d'));
					for ($dd = 0; $dd < $oneBooking['days']; $dd++)
					{
						$dayId = sprintf("%04d-%02d-%02d", $this->year, $this->month, ($firstDay + $dd));
						$data[$placeNdx][$dayId] += $oneBooking['cntParts'];
						if ($dd === 0 && $data[$placeNdx][$dayId] > 1)
							$oneBooking ['onRow'] = $data[$placeNdx][$dayId] - $oneBooking['cntParts'];
					}
				}
			}
		}

		foreach ($this->places as $placeNdx => &$place)
		{
			$maxCnt = 1;
			foreach($data[$placeNdx] as $cnt)
			{
				if ($cnt > $maxCnt)
					$maxCnt = $cnt;
			}
			$place['cntRows'] = $maxCnt;
		}
		$this->dayCounts = $data;
	}

	public function setBookingType ($bookingType)
	{
		$this->bookingType = $bookingType;
		$this->bookingTypeDef = $this->app->cfgItem ('e10pro.bookingTypes.'.$bookingType);
	}

	public function setMonthView($year, $month)
	{
		$this->style = 'month';
		$this->year = $year;
		$this->month = $month;
		$this->dateBegin = utils::createDateTime("$year-$month-01");
		$this->dateEnd = utils::createDateTime($this->dateBegin->format ('Y-m-t'));
	}

	public function setYearView($year)
	{
		$this->style = 'year';
		$this->year = $year;
		$this->dateBegin = utils::createDateTime("$year-01-01");
		$this->dateEnd = utils::createDateTime("$year-12-31");
	}

	public function renderBooking ($year, $month)
	{
	}

	public function renderMonthPlan($year, $month)
	{
		$c = '';

		$firstDay = utils::createDateTime("$year-$month-01");
		$numDays = intval($firstDay->format('t'));

		$c .= "<div style='overflow-y: scroll;'>";
		$c .= "<table class='e10-booking-plan main'>";
		$c .= '<thead><tr>';
		$c .= "<th>".utils::es('Místo').'</th>';

		$weekDay = intval($this->dateBegin->format ('N')) - 1;
		for ($day = 1; $day <= $numDays; $day++)
		{
			$class = 'day';
			if ($weekDay > 4)
				$class .= ' weekend';
			$c .= "<th class='$class'><span class='pre'>".utils::$dayShortcuts[$weekDay].'</span>'.$day.'</th>';
			$weekDay++;
			if ($weekDay === 7)
				$weekDay = 0;
		}
		$c .= '</tr></thead>';


		foreach ($this->places as $placeNdx => $place)
		{
			if (!isset($place['cntRows']))
				continue;
			$cntRows = $place['cntRows'];
			if ($cntRows < $place['cap'])
				$cntRows = $place['cap'];
			$rowSpans = [];

			for ($placePart = 0; $placePart < $cntRows; $placePart++)
			{
				$c .= "<tr data-place='{$place['ndx']}'>";

				$rs = '';
				if ($place['cntRows'] > 1)
					$rs = " rowspan='{$place['cntRows']}'";

				$rowId = '';
				if ($place['cap'] > 1)
					$rowId = "<span class='suf pull-right'>".($placePart+1).'.</span>';

				if ($placePart === 0)
					$c .= "<td class='place'>" . utils::es($place['sn']).$rowId.'</td>';
				else
					$c .= "<td class='place'>&nbsp;".$rowId.'</td>';

				$colspan = 0;

				for ($day = 1; $day <= $numDays; $day++)
				{
					$dayId = sprintf("$year-%02d-%02d", $month, $day);
					$dateCell = utils::createDateTime($dayId);
					$weekDay = intval($dateCell->format('N')) - 1;
					if ($colspan > 0)
					{
						$colspan--;
						continue;
					}
					if (isset ($rowSpans [$placePart][$day]))
					{
						$day += $rowSpans [$placePart][$day] - 1;
						continue;
					}
					$b = NULL;
					if (isset($this->bookings[$placeNdx][$dayId]))
					{
						foreach ($this->bookings[$placeNdx][$dayId] as $bb)
						{
							if ($bb['onRow'] == $placePart)
							{
								$b = $bb;
								break;
							}
						}
					}
					if ($b !== NULL)
					{
						$cellClass = '';
						$cs = '';
						if ($b['days'] > 1)
						{
							$cs = "colspan='".($b['days'])."'";
							$colspan = $b['days'] - 1;
						}
						$rs = '';
						if ($b['cntParts'] > 1)
						{
							$rs = " rowspan='{$b['cntParts']}'";

							if ($b['days'] <= 0)
							{
								$cellClass .= ' e10-warning3';
								$b['days'] = 1;
							}

							for ($ii = 1; $ii < $b['cntParts']; $ii++)
								$rowSpans [$placePart+$ii][$day] = $b['days'];
						}

						if (($placePart+$b['cntParts']) > $place['cap'])
							$cellClass .= ' ob';

						if ($b['docState'] !== 4000)	
							$cellClass .= '  e10-ds e10-docstyle-edit';
						$c .= "<td class='bk$cellClass' data-date='$dayId'$cs$rs>";

						$label = $b['subject'];
						if (!strlen ($label))
							$label = '(neuvedeno)';
						$btn = ['text' => $label, 'docAction' => 'edit', 'table' => $b['table'], 'pk' => $b['ndx'],
										'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId];

						$c .= $this->app()->ui()->composeTextLine($btn);

						$c .= '</td>';
					}
					else
					{
						$class = 'blank';
						if ($weekDay > 4)
							$class .= ' weekend';
						$c .= "<td class='$class' data-date='$dayId'></td>";
					}
				}

				$c .= '</tr>';
			}
		}
		$c .= '</table>';
		$c .= '</div>';

		$c .= '<script>
     $("table.e10-booking-plan>tbody>tr").selectable(
         {
         	filter: "td.blank",
         	cancel: "td.bk",
         	start: function(event, ui) {
     				$("table.e10-booking-plan>tbody>tr>td.ui-selected").removeClass("ui-selected");
        		$(ui.selected).addClass("ui-selected");
    			},
					stop: function() {
						var addButton = $("#e10dashboardWidget button.e10-document-trigger.btn-success");
						var place = $( ".ui-selected", this ).first().parent().attr("data-place");
						var dateBegin = $( ".ui-selected", this ).first().attr("data-date");
						var dateEnd = $( ".ui-selected", this ).last().attr("data-date");
						addButton.attr ("data-addparams", addButton.attr ("data-addparams") + "&__dateBegin="+dateBegin);
						addButton.attr ("data-addparams", addButton.attr ("data-addparams") + "&__dateEnd="+dateEnd);
						addButton.attr ("data-addparams", addButton.attr ("data-addparams") + "&__place="+place);
						addButton.click();
					}
					}
				);
		</script>';

		return $c;
	}

	public function renderMonth($year, $month, $style)
	{
		$c = '';

		$firstDay = utils::createDateTime("$year-$month-01");
		$activeDate = clone $firstDay->modify(('Monday' === $firstDay->format('l')) ? 'monday this week' : 'last monday');

		$c .= "<table class='e10-cal-$style'>";
		if ($style === 'big' || $month < 4)
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

				$class = '';
				if ($thisMonth != $month)
				{
					$class .= ' inactive';
					$title = strval($thisDay) . '.' . strval($thisMonth) . '.';
				}
				if ($style === 'small' && isset ($this->events[$dayId]))
					$class .= ' tooltips';

				$c .= "<td class='day{$class}' data-date='$dayId' tabindex='1'>";

				if ($style === 'small')
					$c .= $title;
				else
				{
					$c .= "<span class='title'>".utils::es($title).'</span>';
				}
				$c .= $this->renderEvents($dayId, $style);

				$c .= '</td>';

				$activeDate->modify('+1 day');
			}
			$c .= '</tr>';

			if ($activeDate->format('m') != $month)
			{
				break;
			}
		}

		if ($style === 'small')
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

	public function renderEvents ($dayId, $style)
	{
		if (!isset ($this->events[$dayId]))
			return '';

		if ($style === 'big')
			return $this->renderEventsBig($dayId);

		return $this->renderEventsSmall($dayId);
	}

	public function renderEventsBig ($dayId)
	{
		$c = "<div class='events' style='display: none;'>";
		foreach ($this->events[$dayId] as $e)
		{
			$event = [
				'text' => $e['subject'], 'class' => 'tag tag-event '.$e['docStateClass'],
				'docAction' => 'edit', 'pk' => $e['ndx'], 'table' => 'e10pro.wkf.messages'];
			if (isset($e['dateBegin']))
				$event['prefix'] = utils::datef($e['dateBegin'], '%T');
			$c .= $this->app()->ui()->composeTextLine($event);
		}
		$c .= '</div>';

		return $c;
	}

	public function renderEventsSmall ($dayId)
	{
		$events = [];
		$counts = [];
		foreach ($this->events[$dayId] as $e)
		{
			$event = [
				'text' => $e['subject'], 'class' => 'tag tag-event '.$e['docStateClass'],
				'docAction' => 'edit', 'pk' => $e['ndx'], 'table' => 'e10pro.wkf.messages'];
			if (isset($e['dateBegin']))
				$event['prefix'] = utils::datef($e['dateBegin'], '%T');
			$events [] = $event;

			if (!isset($counts[$e['docStateClass']]))
				$counts[$e['docStateClass']] = 0;
			$counts[$e['docStateClass']]++;
		}

		$c = "<div class='events'>";
		foreach ($counts as $dsc => $cnt)
			$c .= "<span class='tag $dsc'>$cnt</span>";
		$c .= '</div>';

		$this->tooltips[$dayId] = '';
		foreach ($events as $e)
			$this->tooltips[$dayId] .= $this->app()->ui()->composeTextLine($e);

		return $c;
	}

	public function createCode ()
	{
		if ($this->style === 'month')
		{
			$c = "<div class='padd5'>";
			$c .= $this->renderMonthPlan($this->year, $this->month);
			$c .= '</div>';
/*
			$c .= "<script>
				$('#e10dashboardWidget').find ('table.e10-cal-big tbody>tr>td.day>div.events').each (function () {
			      var oo = $(this).parent();
			    	$(this).width(oo.width() - 1).height(oo.height() - 4).show();
				});
			</script>";*/
		}
		else
			if ($this->style === 'year')
			{
				$c = "<div class='e10-cal-big padd5' style='height: 100%;'>";
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

<?php

namespace E10Pro\Booking;

use \E10\TableView, \E10\utils;

/**
 * Class ViewBookingAgenda
 * @package E10Pro\Booking
 *
 */
class ViewBookingAgenda extends TableView
{
	public function init ()
	{
		$this->enableFullTextSearch = FALSE;
		$this->setPaneMode();

		parent::init();
	}

	function renderPane (&$item)
	{
		$item['pk'] = $item['ndx'];

		$item ['pane'] = ['info' => [], 'class' => 'e10-pane-vitem'];

		$text = [];
		$text[] = ['text' => $item['subject'], 'class' => 'h2'];

		$date = [];
		if ($item['dateBegin'])
			$date[] = ['text' => $item['dateBegin']->format('d.n'), 'prefix' => utils::datef ($item['dateBegin'], '%n'), 'class' => 'info'];
		$date[] = ['icon' => 'icon-angle-double-right', 'text' => '', 'class' => 'info'];
		if ($item['dateEnd'])
			$date[] = ['text' => $item['dateEnd']->format('d.n'), 'prefix' => utils::datef ($item['dateEnd'], '%n'), 'suffix' => $item['dateEnd']->format('Y'), 'class' => 'info'];

		$date[] = ['text' => $item['placeName'], 'class' => 'info pull-right'];

		$item ['pane']['info'][] = ['class' => 'block ', 'value' => $date];
		$item ['pane']['info'][] = ['class' => 'float', 'value' => $text];

		$cmds = [];

		$cmds[] = [
			'class' => 'pull-right', 'text' => 'Otevřít', 'icon' => 'system/actionOpen', 'docAction' => 'edit', 'table' => $this->tableId(),
			'pk'=> $item ['ndx'], 'type' => 'button', 'actionClass' => 'btn btn-xs btn-primary'
		];
		$item ['pane']['info'][] = ['class' => 'commands', 'value' => $cmds];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT bookings.*, places.shortName as placeName from [e10pro_booking_bookings] as bookings';
		$q [] = ' LEFT JOIN e10_base_places as places ON bookings.place = places.ndx';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND (bookings.[subject] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, 'bookings.', ['[dateBegin]', 'ndx']);
		$this->runQuery ($q);
	}
}


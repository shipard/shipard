<?php

namespace E10Pro\Booking;

use \E10\TableView, \E10\TableViewDetail, \Shipard\Form\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableBookings
 * @package E10Pro\Booking
 */
class TableBookings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.booking.bookings', 'e10pro_booking_bookings', 'Rezervace');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['subject']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		if (!$recData['cntParts'])
			$recData['cntParts'] = 100;

		if (isset ($recData ['price']) && $recData ['price'] != 0.0 && $recData['currency'] == '')
			$recData ['currency'] = utils::homeCurrency ($this->app(), $recData ['dateBegin']);
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId !== 'cntParts')
			return parent::columnInfoEnum ($columnId, $valueType, $form);

		$enum[100] = 'Celá kapacita';

		if ($form)
		{
			$bookingType = $this->useBookingCapacity($form->recData);
			if ($bookingType !== FALSE)
			{
				$placeRec = $this->app()->loadItem($form->recData['place'], 'e10.base.places');
				for ($ii = $placeRec['bookingCapacity'] - 1; $ii > 0; $ii--)
					$enum[$ii] = strval($ii);
			}
		}


		return $enum;
	}

	public function useBookingCapacity ($recData)
	{
		if (!isset ($recData['bookingType']))
			return FALSE;
		$bookingType = $this->app()->cfgItem ('e10pro.bookingTypes.'.$recData['bookingType'], FALSE);
		if (!$bookingType)
			return FALSE;
		if (!$bookingType['uc'])
			return FALSE;

		return $bookingType;
	}
}


/**
 * Class ViewBookings
 * @package E10Pro\Booking
 */
class ViewBookings extends TableView
{
	public function init ()
	{
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['subject'];
		//$listItem ['t2'] = $item['shortName'];
		//$listItem ['i2'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * from [e10pro_booking_bookings]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([subject] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, '', ['[dateBegin]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailBooking
 * @package E10Pro\Booking
 */
class ViewDetailBooking extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class FormBooking
 * @package E10Pro\Booking
 */
class FormBooking extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('subject');
			$this->addColumnInput ('dateBegin');
			$this->addColumnInput ('dateEnd');

			if ($this->table->useBookingCapacity($this->recData))
				$this->addColumnInput ('cntParts');

			$this->openRow();
				$this->addColumnInput ('price');
				$this->addColumnInput ('currency');
			$this->closeRow();

			$this->addColumnInput ('phone');
			$this->addColumnInput ('email');

			$this->addColumnInput ('note');

			$this->addColumnInput ('place');
		$this->closeForm ();
	}
}

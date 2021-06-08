<?php

namespace E10Pro\Booking;

use \E10\TableView, \E10\utils, \E10\TableForm, \E10\DbTable;

/**
 * Class TableTypes
 * @package E10Pro\Booking
 */
class TableTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.booking.types', 'e10pro_booking_types', 'Druhy rezervací');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = array ('class' => 'info', 'value' => $recData ['shortName']);
		$hdr ['info'][] = array ('class' => 'title', 'value' => $recData ['fullName']);

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];
		$list [0] = ['ndx' => 0, 'id' => '', 'fullName' => '', 'shortName' => ''];

		$rows = $this->app()->db->query ('SELECT * FROM [e10pro_booking_types] WHERE docState != 9800 ORDER BY [order], [shortName]');

		foreach ($rows as $r)
			$list [$r['ndx']] = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'uc' => intval($r['useCapacity'])];

		// save to file
		$cfg ['e10pro']['bookingTypes'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.bookingTypes.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewTypes
 * @package E10Pro\Booking
 */
class ViewTypes extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];

		if ($item['order'])
			$listItem['i2'] = ['icon' => 'system/iconOrder', 'text' => strval ($item['order'])];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * from [e10pro_booking_types]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[order], [fullName]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormType
 * @package E10Pro\Booking
 */
class FormType extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('order');
			$this->addColumnInput ('useCapacity');
			$this->addColumnInput ('assignTags');
		$this->closeForm ();
	}
}

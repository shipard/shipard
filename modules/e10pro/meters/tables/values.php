<?php

namespace E10Pro\Meters;

use \E10\TableView, \E10\utils, \E10\TableForm, \E10\DbTable;


/**
 * Class TableValues
 * @package E10Pro\Meters
 */
class TableValues extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.meters.values', 'e10pro_meters_values', 'Hodnoty měření');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$meterRec = $this->app()->loadItem ($recData['meter'], 'e10pro.meters.meters');
		$hdr ['info'][] = ['class' => 'title', 'value' => $meterRec['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewValues
 * @package E10Pro\Meters
 */
class ViewValues extends TableView
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
			$listItem['i2'] = ['icon' => 'icon-sort', 'text' => strval ($item['order'])];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * from [e10pro_meters_values] as vals';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		//if ($fts != '')
		//	array_push ($q, " AND ([fullName] LIKE %s OR [shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, 'vals.', ['vals.[datetime], [meter]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormValue
 * @package E10Pro\Meters
 */
class FormValue extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('value');
			$this->addColumnInput ('datetime');
		$this->closeForm ();
	}
}

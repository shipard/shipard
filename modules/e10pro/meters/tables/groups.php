<?php

namespace E10Pro\Meters;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableGroups
 */
class TableGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.meters.groups', 'e10pro_meters_groups', 'Skupiny měřičů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewGroups
 */
class ViewGroups extends TableView
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

		$q [] = 'SELECT * from [e10pro_meters_groups]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[order], [fullName]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormGroup
 */
class FormGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('order');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}

<?php

namespace e10pro\property;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableGroups
 * @package e10pro\property
 */
class TableGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.property.groups', 'e10pro_property_groups', 'Skupiny majetku');
	}

	public function saveConfig ()
	{
		$groups = [];

		$rows = $this->app()->db->query ("SELECT * from [e10pro_property_groups] WHERE docState != 9800 ORDER BY [fullName]");
		forEach ($rows as $r)
		{
			$groups [$r['ndx']] = ['ndx' => $r['ndx'], 'sn' => $r ['shortName'], 'types' => []];
		}

		$typesRows = $this->app()->db->query (
				'SELECT doclinks.srcRecId, doclinks.dstRecId FROM [e10_base_doclinks] as doclinks',
				' WHERE doclinks.linkId = %s', 'e10pro-property-groups-proptypes',
				' AND doclinks.srcTableId = %s', 'e10pro.property.groups'
		);
		foreach ($typesRows as $t)
			$groups [$t['srcRecId']]['types'][] = $t['dstRecId'];

		// -- save to file
		$cfg ['e10pro']['property']['groups'] = $groups;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.property.groups.json', json_encode ($cfg));
	}
}


/**
 * Class ViewGroups
 * @package e10pro\property
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

	public function selectRows ()
	{
		$q [] = 'SELECT * FROM [e10pro_property_groups]';
		array_push($q, ' WHERE 1');

		// -- fulltext
		$fts = $this->fullTextSearch ();
		if ($fts !== '')
			array_push ($q, ' AND ([fullName] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[fullName]', 'ndx']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];

		return $listItem;
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		//$this->linkedPersons = \E10\Base\linkedPersons ($this->table->app(), $this->table, $this->pks);
	}

	function decorateRow (&$item)
	{
		/*if (isset ($this->linkedPersons [$item ['pk']]['e10-persons-groups-balances']))
		{
			$this->linkedPersons [$item ['pk']]['e10-persons-groups-balances'][0]['icon'] = 'system/iconStar';
			$item ['i2'] = $this->linkedPersons [$item ['pk']]['e10-persons-groups-balances'];
		}
		*/
	}
}


/**
 * Class ViewDetailGroup
 * @package e10pro\property
 */
class ViewDetailGroup extends TableViewDetail
{
}


/**
 * Class FormGroup
 * @package e10pro\property
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
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}


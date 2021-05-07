<?php

namespace e10\witems;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableUnits
 * @package e10\witems
 */
class TableUnits extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.units', 'e10_witems_units', 'Jednotky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortcut']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];
		$rows = $this->app()->db->query ('SELECT * from [e10_witems_units] WHERE [docState] != 9800 ORDER BY [shortcut]');

		foreach ($rows as $r)
			$list ['_'.$r['ndx']] = ['text' => $r ['fullName'], 'shortcut' => $r ['shortcut']];

		// -- save to file
		$cfg ['e10']['witems']['units'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10.witems.units.userdefs.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewUnits
 * @package e10\witems
 */
class ViewUnits extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortcut'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10_witems_units]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortcut] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[shortcut]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormUnit
 * @package e10\witems
 */
class FormUnit extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortcut');
		$this->closeForm ();
	}
}


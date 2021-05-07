<?php

namespace E10\Base;

use \E10\Application, \E10\utils, \E10\TableView, \E10\TableViewWidget, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;

class TableTimeline extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.timeline", "e10_base_timeline", "Timeline");
	}
} // class TableTimeline


/* 
 * ViewTimelineAll
 * 
 */

class ViewTimelineAll extends TableView
{
	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT tl.*, persons.fullName as personName FROM [e10_base_timeline] as tl ' .
						'LEFT JOIN e10_persons_persons as persons ON tl.person = persons.ndx WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
/*			array_push ($q, " AND ([personName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");
			array_push ($q, " AND ([eventTitle] LIKE %s", '%'.$fts.'%');
			array_push ($q, ") ");*/
		}

		array_push ($q, ' ORDER BY [date] DESC, [ndx] DESC' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows


	public function renderRow ($item)
	{
		$table = $this->table->app()->table ($item['tableid']);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = $item['personName'];
		$listItem ['i1'] = utils::datef ($item['date']);

		/*
		$props2 [] = array ('i' => 'table', 'text' => $table->tableName ());
		$props2 [] = array ('i' => 'file', 'text' => $title,
													'docAction' => 'edit', 'table' => $item['tableid'], 'pk'=> $item ['recid']);

		$listItem ['t2'] = $props2;

		$listItem ['i2'] = \E10\df ($item['created']);

		$props3 [] = array ('i' => 'road', 'text' => $item ['ipaddress']);
		$props3 [] = array ('icon' => 'x-pc', 'text' => $item ['deviceId']);
		$listItem ['t3'] = $props3;
*/
		return $listItem;
	}

	public function createDetails ()
	{
		return array ();
	}
	
	public function createToolbar ()
	{
		return array ();
	} // createToolbar
} // class ViewTimelineAll


/**
 * ViewDetailTimeline
 *
 */

class ViewDetailTimeline extends TableViewDetail
{
	public function createToolbar ()
	{
		$toolbar = array ();
		return $toolbar;
	} // createToolbar
}


/* 
 * FormTimeline
 * 
 */

class FormTimeline extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			//$this->addColumnInput ("fullName");
		$this->closeForm ();
	}

} // class FormTimeline


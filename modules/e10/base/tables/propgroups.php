<?php

namespace E10\Base;

use \E10\Application, \E10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;

class TablePropgroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.propgroups", "e10_base_propgroups", "Skupiny vlastností");
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['id'] = $this->defaultId($recData);
			$this->app()->db()->query ('UPDATE [e10_base_propgroups] SET [id] = %s WHERE [ndx] = %i', $recData['id'], $recData['ndx']);
		}
		parent::checkAfterSave2($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
			$recData['id'] = $this->defaultId($recData);
	}

	public function saveConfig ()
	{
		//$allProperties = \E10\Base\allPropertiesCfg ($this->app());
		$allPropGroups = \E10\Base\allPropertiesGroupsCfg($this->app());

		// save types to file
		$cfg ['e10']['base']['propertiesGroups'] = $allPropGroups;
		file_put_contents(__APP_DIR__ . '/config/_e10.base.propertiesGroups.json', utils::json_lint(json_encode ($cfg)));
	}

	function defaultId($recData)
	{
		return
			base_convert(intval($this->app()->cfgItem ('dsid')), 10, 36)
			.'_'.
			base_convert($recData['ndx'], 10, 36);
	}
} // class TablePropGroups


/* 
 * ViewPropGroups
 * 
 */

class ViewPropGroups extends TableView
{
	private $properties;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = $item['id'];

		$listItem ['icon'] = 'x-properties';

		return $listItem;
	}

	function decorateRow (&$item)
	{
		$item ['t2'] = $this->properties [$item ['pk']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = "SELECT * from [e10_base_propgroups] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [id] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		array_push ($q, ' ORDER BY [fullName], [ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
		$pkeys = implode(', ', $this->pks);

		// -- propsgoups
		$sql = "SELECT links.ndx, links.linkId as linkId, links.srcRecId as propgroup, props.fullName as fullName from e10_base_doclinks as links " .
					 "LEFT JOIN e10_base_propdefs as props ON links.dstRecId = props.ndx " .
					 "where dstTableId = 'e10.base.propdefs' AND srcTableId = 'e10.base.propgroups' AND links.srcRecId IN ($pkeys)";
		$propdefs = $this->table->app()->db->query ($sql);

		$ptxt = array ();
		foreach ($propdefs as $r)
			$ptxt [$r ['propgroup']][] = $r ['fullName'];
		forEach ($ptxt as $propId => $pdefs)
			$this->properties [$propId] = implode (', ', $pdefs);
	}

} // class ViewPropGroups


/**
 * Základní detail Skupiny vlastností
 *
 */

class ViewDetailPropGroup extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$item = $this->item;
		$info = $item ['fullName'];
		return $this->defaultHedearCode ('x-properties', $item ['fullName'], $info);
	}
}


/* 
 * FormPropGroup
 * 
 */

class FormPropGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
//		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->addColumnInput ("fullName");
			$this->addColumnInput ("shortName");
			//$this->addColumnInput ("id");
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}	

	public function createHeaderCode ()
	{
		$item = $this->recData;
		$info = '';
		return $this->defaultHedearCode ('x-properties', $item ['fullName'], $info);
	}
} // class FormPropGroup


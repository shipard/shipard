<?php

namespace e10\users;

use \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \Shipard\Utils\Utils;


/**
 * Class TableRoles
 */
class TableRoles extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.users.roles', 'e10_users_roles', 'Role uživatelů');
	}

  public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['fullName']];
		return $hdr;
	}
}


/**
 * Class ViewRoles
 */
class ViewRoles extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;

		parent::init();

		//$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => $item['systemId'], 'class' => 'id'];

    $flags = [];

    if ($item['uiFullName'])
      $flags[] = ['text' => $item['uiFullName'], 'class' => 'label label-default', 'icon' => 'tables/e10.ui.uis'];

    $listItem['t2'] = $flags;

		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [roles].*,');
    array_push ($q, ' uis.fullName AS uiFullName');
		array_push ($q, ' FROM [e10_users_roles] AS [roles]');
		array_push ($q, ' LEFT JOIN e10_ui_uis AS uis ON [roles].ui = uis.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [roles].[fullName] LIKE %s', '%' . $fts . '%');
			array_push($q, ')');
		}

    array_push ($q, ' ORDER BY ndx');
    array_push ($q, $this->sqlLimit ());
		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class FormRole
 */
class FormRole extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput('fullName');
			$this->addColumnInput('ui');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailRole
 */
class ViewDetailRole extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


<?php

namespace e10\witems;

use \Shipard\Viewer\TableViewGrid, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TableItemsGroupsItems
 */
class TableItemsGroupsItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.itemsGroupsItems', 'e10_witems_itemsGroupsItems', 'Položky skupin položek');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

//		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
//		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];

		return $hdr;
	}
}


/**
 * class ViewItemsGroupsItems
 */
class ViewItemsGroupsItems extends TableViewGrid
{
	public function init ()
	{
		parent::init();

		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$g = [
			'group' => 'Skupina',
			'item' => 'Položka',
			'note' => 'Poznámka',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['group'] = $item['groupFullName'];
		$listItem ['item'] = $item['itemFullName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [ig].* ');
		array_push ($q, ', [items].[fullName] AS [itemFullName]');
    array_push ($q, ', [groups].[fullName] AS [groupFullName]');
		array_push ($q, ' FROM [e10_witems_itemsGroupsItems] AS [ig]');
		array_push ($q, ' LEFT JOIN [e10_witems_itemsGroups] AS [groups] ON [ig].[itemsGroup] = [groups].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [ig].[item] = [items].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{

			array_push ($q, ' AND (');
			array_push ($q, ' [items].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [items].[id] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [groups].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[ig].', ['[groups].[order]', '[groups].[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormItemsGroupsItem
 */
class FormItemsGroupsItem extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Účet', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('itemsGroup');
					$this->addColumnInput ('item');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailBalanceAccount
 */
class ViewDetailItemsGroupsItem extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

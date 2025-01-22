<?php

namespace e10\witems;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;


/**
 * class TableItemsGroups
 */
class TableItemsGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.itemsGroups', 'e10_witems_itemsGroups', 'Skupiny položek');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];

		return $hdr;
	}
}


/**
 * class ViewItemsGroups
 */
class ViewItemsGroups extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['i2'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [itemsGroups].* ');
		array_push ($q, ' FROM [e10_witems_itemsGroups] AS [itemsGroups]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [itemsGroups].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [itemsGroups].[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[itemsGroups].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormItemsGroup
 */
class FormItemsGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Druh', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
          $this->addColumnInput ('id');
					$this->addSeparator(self::coH4);
          $this->addColumnInput ('order');
					$this->addSeparator(self::coH4);
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
 * class ViewDetailItemsGroup
 */
class ViewDetailItemsGroup extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10.witems.dc.DCItemsGroup');
	}
}

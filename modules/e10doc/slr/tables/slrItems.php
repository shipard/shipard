<?php

namespace e10doc\slr;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TableSlrItems
 */
class TableSlrItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.slrItems', 'e10doc_slr_slrItems', 'Mzdové položky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewSlrItems
 */
class ViewSlrItems extends TableView
{
	var $itemTypes;

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$this->itemTypes = $this->app()->cfgItem('e10doc.slr.slrItemTypes');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => $item['importId'], 'class' => 'id'];

		$it = $this->itemTypes[$item['itemType']];

		$props = [];
		$props[] = ['text' => $it['fn'], 'class' => 'label label-info'];

		$accItemTxt = '';
		if ($item['debsAccountIdDr'] && $item['debsAccountIdCr'] && $item['debsAccountIdBal'])
			$accItemTxt = $item['debsAccountIdDr'].' ⨉ '.$item['debsAccountIdBal'].' > '.$item['debsAccountIdCr'];
		elseif ($item['debsAccountIdDr'] && $item['debsAccountIdCr'])
			$accItemTxt = $item['debsAccountIdDr'].' ⨉ '.$item['debsAccountIdCr'];

		$props[] = ['text' => $accItemTxt, 'class' => 'label label-default'];

		if ($item['negativeAmount'])
			$props[] = ['text' => '✖️ -1', 'class' => 'label label-info'];

		if (count($props))
			$listItem ['t2'] = $props;

		if ($item['dueDay'])
			$listItem['i2']	= ['text' => strval($item['dueDay']), 'class' => 'label label-info', 'icon' => 'system/iconCalendar'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [slrItems].*,');
		array_push ($q, ' accDr.debsAccountId AS debsAccountIdDr,');
		array_push ($q, ' accCr.debsAccountId AS debsAccountIdCr,');
		array_push ($q, ' accBal.debsAccountId AS debsAccountIdBal');
		array_push ($q, ' FROM [e10doc_slr_slrItems] AS [slrItems]');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS accDr ON slrItems.accItemDr = accDr.ndx');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS accCr ON slrItems.accItemCr = accCr.ndx');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS accBal ON slrItems.accItemBal = accBal.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [slrItems].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [slrItems].[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[slrItems].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormSlrItem
 */
class FormSlrItem extends TableForm
{
	public function renderForm ()
	{
		$itemType = $this->app()->cfgItem('e10doc.slr.slrItemTypes.'.$this->recData['itemType']);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Položka', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('importId');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('itemType');
					if ($itemType['payee'] === 2)
						$this->addColumnInput ('moneyOrg');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('accItemDr');
					if (!($itemType['disableAccItemBal'] ?? 0))
						$this->addColumnInput ('accItemBal');
					$this->addColumnInput ('accItemCr');
					if ($itemType['payee'])
					{
						$this->addSeparator(self::coH4);
						$this->addColumnInput ('dueDay');
					}
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('negativeAmount');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSlrItem
 */
class ViewDetailSlrItem extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('e10doc.slr.dc.DCImport');
	}
}

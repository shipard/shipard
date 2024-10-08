<?php

namespace e10pro\bcards;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableCards
 */
class TableCards extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.bcards.cards', 'e10pro_bcards_cards', 'Vizitky');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
		if (isset ($recData['id1']) && $recData['id1'] === '')
		{
			$recData['id1'] = Utils::createToken(8, FALSE, TRUE);
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewCards
 */
class ViewCards extends TableView
{
	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['t2'] = $item['shortName'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [cards].* ');
    array_push ($q, ' FROM [e10pro_bcards_cards] AS [cards]');
    array_push ($q, '');
    array_push ($q, '');
    array_push ($q, '');
    array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, 'AND (');
			array_push ($q, '[fullName] LIKE %s ', '%'.$fts.'%');
      array_push ($q, ')');
    }

		$this->queryMain ($q, '[cards].', ['[fullName]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormCard
 */
class FormCard extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'formParameters'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('bcardType');
					$this->addColumnInput ('org');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('title');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('phone');
					$this->addColumnInput ('email');
					$this->addColumnInput ('web');
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('id1');
					$this->addColumnInput ('id2');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailCard
 */
class ViewDetailCard extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.bcards.libs.dc.DCBCard');
	}
}

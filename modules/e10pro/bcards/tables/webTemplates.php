<?php

namespace e10pro\bcards;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;
use \Shipard\Application\DataModel;


/**
 * class TableWebTemplates
 */
class TableWebTemplates extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.bcards.webTemplates', 'e10pro_bcards_webTemplates', 'Webové šablony');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewWebTemplates
 */
class ViewWebTemplates extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [templates].* ');
    array_push ($q, ' FROM [e10pro_bcards_webTemplates] AS [templates]');
    array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, 'AND (');
			array_push ($q, '[fullName] LIKE %s ', '%'.$fts.'%');
      array_push ($q, ')');
    }

		$this->queryMain ($q, '[templates].', ['[fullName]', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormWebTemplate
 */
class FormWebTemplate extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Šablona', 'icon' => 'formText'];
		$tabs ['tabs'][] = ['text' => 'CSS', 'icon' => 'formText'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
          $this->addInputMemo ('codeTemplate', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
          $this->addInputMemo ('codeStyle', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailWebTemplate
 */
class ViewDetailWebTemplate extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.bcards.libs.dc.DCBCardWebTemplate');
	}
}

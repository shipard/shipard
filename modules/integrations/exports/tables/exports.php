<?php

namespace integrations\exports;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Application\DataModel;
use \Shipard\Utils\Utils;


/**
 * class TableExports
 */
class TableExports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.exports.exports', 'integrations_exports_exports', 'Exporty dat', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave(&$recData, $ownerData = NULL)
  {
    if (!isset($recData['exportId']) || Utils::dateIsBlank($recData['exportId']))
			$recData['exportId'] = Utils::createToken(24);
		if (!isset($recData['apiKey']) || $recData['apiKey'] === '')
			$recData['apiKey'] = Utils::createToken(64);
		parent::checkBeforeSave($recData, $ownerData);
	}
}


/**
 * class ViewExports
 */
class ViewExports extends TableView
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
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t2'] = [];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, 'SELECT [exports].*');
		array_push ($q, ' FROM [integrations_exports_exports] AS [exports]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormExport
 */
class FormExport extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', TRUE);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Export', 'icon' => 'tables/integrations.exports.exports'];
	    $tabs ['tabs'][] = ['text' => 'Šablona', 'icon' => 'formText'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('exportType');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('exportId');
					$this->addColumnInput ('apiKey');
				$this->closeTab();
        $this->openTab (TableForm::ltNone);
          $this->addInputMemo ('codeTemplate', NULL, TableForm::coFullSizeY, DataModel::ctCode);
        $this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailExport
 */
class ViewDetailExport extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

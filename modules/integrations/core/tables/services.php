<?php

namespace integrations\core;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableServices
 * @package integrations\core
 */
class TableServices extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.core.services', 'integrations_core_services', 'Služby', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewServices
 * @package integrations\core
 */
class ViewServices extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [integrations_core_services]';
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
 * Class FormService
 * @package integrations\core
 */
class FormService extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Služba', 'icon' => 'icon-plug'];
			$tabs ['tabs'][] = ['text' => 'Klíč', 'icon' => 'tables/e10.persons.keys'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('serviceType');
					$this->addColumnInput ('fullName');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('authKey', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailService
 * @package integration\base
 */
class ViewDetailService extends TableViewDetail
{
}



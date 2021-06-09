<?php

namespace integrations\core;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableTasks
 * @package integrations\core
 */
class TableTasks extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.core.tasks', 'integrations_core_tasks', 'Úlohy', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewTasks
 * @package integrations\core
 */
class ViewTasks extends TableView
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

		/*
		$props = [];
		if (count($props))
			$listItem ['t2'] = $props;
		*/

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [integrations_core_tasks] AS tasks';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' tasks.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'tasks.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormTask
 * @package integrations\core
 */
class FormTask extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Služba', 'icon' => 'system/iconCogs'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('service');
					$this->addColumnInput ('taskType');
					if ($this->recData['taskType'] === 'download-files')
						$this->addColumnInput ('dstTray');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailTask
 * @package integrations\base
 */
class ViewDetailTask extends TableViewDetail
{
}



<?php

namespace e10\base;


use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableTasks
 * @package e10\base
 */
class TableTasks extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.tasks', 'e10_base_tasks', 'Systémové úlohy');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function addTask ($taskRec)
	{
		$newTaskNdx = $this->dbInsertRec ($taskRec);
		$this->docsLog ($newTaskNdx);

		utils::dsCmd ($this->app(), 'appTask', ['taskNdx' => $newTaskNdx]);
	}

	public function runTask ($taskNdx)
	{
		$taskRecData = $this->loadItem ($taskNdx);
		if (!$taskRecData)
			return;

		$this->db()->query('UPDATE [e10_base_tasks] SET docState = 1200, docStateMain = 1, timeBegin = NOW() WHERE ndx = %i', $taskNdx);

		$params = json_decode($taskRecData['params'], TRUE);

		$actionEngine = $this->app()->createObject($taskRecData['classId']);
		$actionEngine->setParams ($params);
		$actionEngine->init();
		$actionEngine->run();

		$this->db()->query('UPDATE [e10_base_tasks] SET docState = 4000, docStateMain = 2, timeEnd = NOW() WHERE ndx = %i', $taskNdx);
	}
}


/**
 * Class ViewTasks
 * @package e10\base
 */
class ViewTasks extends TableView
{
	public function init ()
	{
		parent::init();

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'done', 'title' => 'Hotovo'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['title'];
		$listItem ['i1'] = ['text' => '#'.utils::nf($item ['ndx']), 'class' => 'id'];

		$props = [];
		$props[] = ['text' => utils::datef ($item ['timeCreate'], '%D, %T'), 'icon' => 'icon-star-o'];

		$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10_base_tasks]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		// -- active
		if ($mainQuery === 'active' || $mainQuery == '')
			array_push ($q, ' AND [docStateMain] <= 1');
		// -- done
		if ($mainQuery === 'done')
			array_push ($q, ' AND [docStateMain] = 2');
		// -- trash
		if ($mainQuery === 'trash')
			array_push ($q, ' AND [docStateMain] = 4');

		if ($mainQuery === 'trash' || $mainQuery === 'all')
			array_push ($q, ' ORDER BY [ndx]');
		else
			array_push ($q, ' ORDER BY [ndx] DESC');

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailTask
 * @package e10\base
 */
class ViewDetailTask extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class FormTask
 * @package e10\base
 */
class FormTask extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Úloha', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Parametry', 'icon' => 'icon-location-arrow'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput('title');
					$this->addColumnInput('classId', TableForm::coReadOnly);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('params', NULL, TableForm::coFullSizeY|TableForm::coReadOnly);
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

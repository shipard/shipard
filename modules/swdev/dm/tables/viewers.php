<?php

namespace swdev\dm;


use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Viewer\TableViewPanel, \E10\DbTable, \E10\utils;
use \e10\base\libs\UtilsBase;


/**
 * Class TableViewers
 * @package swdev\dm
 */
class TableViewers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.viewers', 'swdev_dm_viewers', 'Prohlížeče');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['classId']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}
}


/**
 * Class ViewViewers
 * @package swdev\dm
 */
class ViewViewers extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['classId'];
		$listItem ['i1'] = ['text' => '#'.utils::nf($item['ndx']), 'class' => 'id'];
		$listItem ['t2'] = $item['id'];
		$listItem ['i2'] = ['text' => $item['tableName'], 'icon' => 'icon-table', 'suffix' => '#'.$item['table']];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [viewers].*, [tbls].[name] AS [tableName]';

		array_push ($q, ' FROM [swdev_dm_viewers] AS [viewers]');
		array_push ($q, ' LEFT JOIN [swdev_dm_tables] AS [tbls] ON [viewers].[table] = [tbls].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([viewers].[name] LIKE %s', '%'.$fts.'%',
				' OR [viewers].[id] LIKE %s', '%'.$fts.'%',
				' OR [viewers].[classId] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[viewers].', ['[viewers].[classId]', '[viewers].[ndx]']);
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormViewer
 * @package swdev\dm
 */
class FormViewer extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Prohlížeč', 'icon' => 'icon-window-restore'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('table');
					$this->addColumnInput ('id');
					$this->addColumnInput ('classId');
					$this->addColumnInput ('name');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailViewer
 * @package swdev\dm
 */
class ViewDetailViewer extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

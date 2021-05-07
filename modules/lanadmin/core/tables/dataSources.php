<?php

namespace lanadmin\core;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableDataSources
 * @package lanadmin\core
 */
class TableDataSources extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('lanadmin.core.dataSources', 'lanadmin_core_dataSources', 'Zdroje dat');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['shortName']];

		return $hdr;
	}
}


/**
 * Class ViewDataSources
 * @package lanadmin\core
 */
class ViewDataSources extends TableView
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
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$listItem ['t2'] = $item['dsUrl'];

		$listItem ['i2'] = [];
		if ($item ['order'])
			$listItem ['i2'][] = ['icon' => 'icon-sort', 'text' => utils::nf ($item ['order'], 0), 'class' => ''];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [ds].* FROM [lanadmin_core_dataSources] AS [ds]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [dsUrl] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[ds].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormDataSource
 * @package lanadmin\core
 */
class FormDataSource extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Zdroj dat', 'icon' => 'icon-database'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('dsUrl');
					$this->addColumnInput ('order');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDataSource
 * @package lanadmin\core
 */
class ViewDetailDataSource extends TableViewDetail
{
}


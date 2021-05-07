<?php

namespace swdev\hw;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableFW
 * @package swdev\hw
 */
class TableFW extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.hw.fw', 'swdev_hw_fw', 'Firmware');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}
}


/**
 * Class ViewFW
 * @package swdev\hw
 */
class ViewFW extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();

		//$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.utils::nf($item['ndx']), 'class' => 'id'];
		$listItem ['t2'] = $item['id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [fw].*';

		array_push ($q, ' FROM [swdev_hw_fw] AS [fw]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([fw].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [fw].[shortName] LIKE %s', '%'.$fts.'%',
				' OR [fw].[id] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[fw].', ['[fw].[fullName]', '[fw].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormFW
 * @package swdev\hw
 */
class FormFW extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'FW', 'icon' => 'icon-file-archive-o'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');

					$this->addColumnInput ('vendor');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailFW
 * @package swdev\hw
 */
class ViewDetailFW extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

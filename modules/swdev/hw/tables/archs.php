<?php

namespace swdev\hw;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableArchs
 * @package swdev\hw
 */
class TableArchs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.hw.archs', 'swdev_hw_archs', 'Architektury HW');
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
 * Class ViewArchs
 * @package swdev\hw
 */
class ViewArchs extends TableView
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

		$q [] = 'SELECT [archs].*';

		array_push ($q, ' FROM [swdev_hw_archs] AS [archs]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([archs].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [archs].[shortName] LIKE %s', '%'.$fts.'%',
				' OR [archs].[id] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[archs].', ['[archs].[fullName]', '[archs].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormArch
 * @package swdev\hw
 */
class FormArch extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Architektura', 'icon' => 'icon-microchip'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailArch
 * @package swdev\hw
 */
class ViewDetailArch extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

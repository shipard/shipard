<?php

namespace swdev\hw;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableVendors
 * @package swdev\hw
 */
class TableVendors extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.hw.vendors', 'swdev_hw_vendors', 'Výrobci HW');
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
 * Class ViewVendors
 * @package swdev\hw
 */
class ViewVendors extends TableView
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

		$q [] = 'SELECT [vendors].*';

		array_push ($q, ' FROM [swdev_hw_vendors] AS [vendors]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([vendors].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [vendors].[shortName] LIKE %s', '%'.$fts.'%',
				' OR [vendors].[id] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[vendors].', ['[vendors].[fullName]', '[vendors].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormVendor
 * @package swdev\hw
 */
class FormVendor extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Výrobce', 'icon' => 'icon-industry'];

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
 * Class ViewDetailVendor
 * @package swdev\hw
 */
class ViewDetailVendor extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

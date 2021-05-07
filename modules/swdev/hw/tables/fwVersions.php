<?php

namespace swdev\hw;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableFWVersions
 * @package swdev\hw
 */
class TableFWVersions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.hw.fwVersions', 'swdev_hw_fwVersions', 'Verze Firmware');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		//$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['version']];

		return $h;
	}
}


/**
 * Class ViewFWVersions
 * @package swdev\hw
 */
class ViewFWVersions extends TableView
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
		$listItem ['t1'] = $item['fwName'];
		$listItem ['i1'] = ['text' => '#'.utils::nf($item['ndx']), 'class' => 'id'];
		$listItem ['t2'] = $item['version'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [fwVersions].*, [fw].fullName AS fwName';

		array_push ($q, ' FROM [swdev_hw_fwVersions] AS [fwVersions]');
		array_push ($q, ' LEFT JOIN [swdev_hw_fw] AS [fw] ON [fwVersions].[fw] = [fw].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([fw].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [fw].[shortName] LIKE %s', '%'.$fts.'%',
				' OR [fw].[id] LIKE %s', '%'.$fts.'%',
				' OR [fwVersions].[version] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[fwVersions].', ['[fwVersions].[releaseDate] DESC', '[fwVersions].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormFWVersion
 * @package swdev\hw
 */
class FormFWVersion extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'FW', 'icon' => 'icon-hashtag'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fw');
					$this->addColumnInput ('version');
					$this->addColumnInput ('releaseDate');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailFWVersion
 * @package swdev\hw
 */
class ViewDetailFWVersion extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

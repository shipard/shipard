<?php

namespace swdev\icons;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableSetsVariants
 * @package swdev\icons
 */
class TableSetsVariants extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.icons.setsVariants', 'swdev_icons_setsVariants', 'Varianty Sady ikon');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];

		return $h;
	}
}


/**
 * Class ViewSetsVariants
 * @package swdev\icons
 */
class ViewSetsVariants extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = $item['shortName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [setsVariants].*';

		array_push ($q, ' FROM [swdev_icons_setsVariants] AS [setsVariants]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([setsVariants].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [setsVariants].[shortName] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[setsVariants].', ['[setsVariants].[fullName]', '[setsVariants].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormSetVariant
 * @package swdev\icons
 */
class FormSetVariant extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Varianta', 'icon' => 'icon-th'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('iconsSet');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSetVariant
 * @package swdev\icons
 */
class ViewDetailSetVariant extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

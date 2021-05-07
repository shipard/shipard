<?php

namespace swdev\icons;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableSets
 * @package swdev\icons
 */
class TableSets extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.icons.sets', 'swdev_icons_sets', 'Sady ikon');
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
 * Class ViewSets
 * @package swdev\icons
 */
class ViewSets extends TableView
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

		if ($item['useForAppIcons'] == 1)
			$listItem ['i2'] = ['text' => 'APP', 'class' => 'label label-info'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [sets].*';

		array_push ($q, ' FROM [swdev_icons_sets] AS [sets]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([sets].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [sets].[shortName] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[sets].', ['[sets].[fullName]', '[sets].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormSet
 * @package swdev\icons
 */
class FormSet extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Sada', 'icon' => 'icon-th'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('srcLanguage');
					$this->addColumnInput ('pathSvgs');
					$this->addColumnInput ('pathFonts');
					$this->addColumnInput ('primaryVariantAdm');
					$this->addColumnInput ('useForAppIcons');
					$this->addSeparator(self::coH2);
					$this->layoutOpen(self::ltHorizontal);
						$this->addColumnInput ('isPrimaryForAppIconsAdm', self::coRight);
					$this->layoutClose();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSet
 * @package swdev\icons
 */
class ViewDetailSet extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

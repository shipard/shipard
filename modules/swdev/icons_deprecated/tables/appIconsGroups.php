<?php

namespace swdev\icons;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableAppIconsGroups
 * @package swdev\icons
 */
class TableAppIconsGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.icons.appIconsGroups', 'swdev_icons_appIconsGroups', 'Skupiny ikon aplikace');
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
 * Class ViewAppIconsGroups
 * @package swdev\icons
 */
class ViewAppIconsGroups extends TableView
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
		$listItem ['i2'] = ['text' => $item['id'], 'class' => 'label label-info'];
		$listItem ['t2'] = $item['shortName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [groups].*';

		array_push ($q, ' FROM [swdev_icons_appIconsGroups] AS [groups]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([groups].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [groups].[shortName] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[groups].', ['[groups].[fullName]', '[groups].[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormAppIconGroup
 * @package swdev\icons
 */
class FormAppIconGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Skupina', 'icon' => 'icon-clone'];
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
 * Class ViewDetailAppIconGroup
 * @package swdev\icons
 */
class ViewDetailAppIconGroup extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.icons.dc.AppIconGroup');
	}
}

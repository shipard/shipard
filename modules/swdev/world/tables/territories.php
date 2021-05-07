<?php

namespace swdev\world;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableTerritories
 * @package swdev\world
 */
class TableTerritories extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.territories', 'swdev_world_territories', 'Oblasti a uskupení');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}
}


/**
 * Class ViewTerritories
 * @package swdev\world
 */
class ViewTerritories extends TableView
{
	var $territoriesTypes;

	public function init ()
	{
		parent::init();

		$this->setMainQueries ();

		$this->setPanels (TableView::sptQuery);

		$this->territoriesTypes = $this->app()->cfgItem('swdev.world.territoriesTypes');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		//$listItem ['emoji'] = $item['flag'];
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = $item['id'];

		$listItem ['i2'] = [
			'text' => $this->territoriesTypes[$item['territoryType']]['name'], 'class' => 'label label-default'
		];

		//$listItem ['t2'][] = ['text' => $item['cca2'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT territories.*';

		array_push ($q, ' FROM [swdev_world_territories] AS territories');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' territories.[name] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR territories.[id] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR EXISTS (SELECT territory FROM swdev_world_territoriesTr ',
				'WHERE territories.ndx = territory AND name LIKE %s', '%'.$fts.'%',
				')');
			array_push($q, ')');
		}

		$this->queryMain ($q, 'territories.', ['territories.[id]', 'territories.[ndx]']);
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $params];
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormTerritory
 * @package swdev\world
 */
class FormTerritory extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Oblast', 'icon' => 'icon-map'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('territoryType');
					$this->addColumnInput ('name');
					$this->addColumnInput ('id');
					$this->addColumnInput ('flag');
					$this->addColumnInput ('urlWikipedia');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailTerritory
 * @package swdev\world
 */
class ViewDetailTerritory extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class ViewDetailTerritoryTr
 * @package swdev\world
 */
class ViewDetailTerritoryTr extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer('swdev.world.territoriesTr', 'swdev.world.ViewTerritoriesTr', ['territory' => $this->item['ndx']]);
	}
}

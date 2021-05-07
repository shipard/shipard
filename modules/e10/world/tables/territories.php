<?php

namespace e10\world;

use \e10\TableView, \e10\TableViewDetail, \e10\DbTable;


/**
 * Class TableTerritories
 * @package e10\world
 */
class TableTerritories extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.territories', 'e10_world_territories', 'Oblasti a uskupení');
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
 * @package e10\world
 */
class ViewTerritories extends TableView
{
	var $territoriesTypes;

	public function init ()
	{
		parent::init();

		$this->territoriesTypes = $this->app()->cfgItem('e10.world.territoriesTypes');
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

		array_push ($q, ' FROM [e10_world_territories] AS territories');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' territories.[name] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR territories.[id] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR EXISTS (SELECT territory FROM e10_world_territoriesTr ',
				'WHERE territories.ndx = territory AND name LIKE %s', '%'.$fts.'%',
				')');
			array_push($q, ')');
		}

		$this->queryMain ($q, 'territories.', ['territories.[id]', 'territories.[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailTerritory
 * @package e10\world
 */
class ViewDetailTerritory extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

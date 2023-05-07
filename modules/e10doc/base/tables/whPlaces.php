<?php

namespace E10Doc\Base;

use \Shipard\Viewer\TableViewPanel;
use \e10\utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;


/**
 * Class TableWHPlaces
 * @package E10Doc\Base
 */
class TableWHPlaces extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.whPlaces', 'e10doc_base_whPlaces', 'Skladovací místa');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['subTitle']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function loadTree(&$tree, $warehouseNdx = 0)
	{
		$this->loadTree_warehouses($tree);
	}

	function loadTree_warehouses(&$tree, $warehouseNdx = 0)
	{
		$q[] = 'SELECT whs.*';
		array_push($q, ' FROM [e10doc_base_warehouses] AS whs');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND whs.docState IN %in', [4000, 8000]);
		array_push($q, ' ORDER BY whs.[order], whs.fullName');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$whId = 'W'.$r['ndx'];
			$i = [['text' => $r['fullName'], 'icon' => 'icon-folder-o', 'addParams' => ['warehouse' => $r['ndx']], 'subItems' => []]];

			$this->loadTree_places($i[0]['subItems'], $r['ndx'], 0);
			if (!count($i[0]['subItems']))
				unset($i[0]['subItems']);

			$tree[$whId] = $i;
		}
	}

	function loadTree_places(&$tree, $warehouseNdx, $ownerPlaceNdx)
	{
		$q [] = 'SELECT whp.*';
		array_push ($q, ' FROM [e10doc_base_whPlaces] AS whp');
		array_push ($q, ' WHERE 1');

		if ($warehouseNdx)
			array_push ($q, ' AND [warehouse] = %i', $warehouseNdx);

		array_push ($q, ' AND [ownerPlace] = %i', $ownerPlaceNdx);

		array_push($q, ' ORDER BY whp.[order], whp.title');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$wpId = 'P'.$r['ndx'];
			$i = [
				['text' => $r['title'], 'icon' => 'icon-folder-o', 'addParams' => ['ownerPlace' => strval($r['ndx']), 'warehouse' => $warehouseNdx], 'subItems' => []]
			];

			$this->loadTree_places($i[0]['subItems'], $warehouseNdx, $r['ndx']);
			if (!count($i[0]['subItems']))
				unset($i[0]['subItems']);

			$tree[$wpId] = $i;


		}
	}
}

/**
 * Class ViewWHPlaces
 * @package E10Doc\Base
 */
class ViewWHPlaces extends TableView
{

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = $item['subTitle'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT whp.*';
		array_push ($q, ' FROM [e10doc_base_whPlaces] AS whp');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' whp.[title] LIKE %s', '%'.$fts.'%', ' OR whp.[subTitle] LIKE %s', '%'.$fts.'%',
				' OR whp.[id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'whp.', ['[order]', '[id]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewWHPlacesTree
 * @package E10Doc\Base
 */
class ViewWHPlacesTree extends TableView
{
	var $placesTree = [];
	var $treeParam = NULL;

	/** @var \E10Doc\Base\TableWHPlaces */
	var $tableWHPlaces = NULL;

	public function init ()
	{
		parent::init();

		$this->tableWHPlaces = $this->app()->table('e10doc.base.whPlaces');

		$this->enableDetailSearch = TRUE;
		$this->usePanelLeft = TRUE;
		$this->linesWidth = 40;

		$this->placesTree['---'] = [['text' => 'Vše', 'icon' => 'icon-file-o', ]];
		$this->tableWHPlaces->loadTree($this->placesTree);

		$this->treeParam = new \E10\Params ($this->app);
		$this->treeParam->addParam('switch', 'place', ['title' => '', 'switch' => $this->placesTree, 'list' => 1]);
		$this->treeParam->detectValues();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = $item['subTitle'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT whp.*';
		array_push ($q, ' FROM [e10doc_base_whPlaces] AS whp');
		array_push ($q, ' WHERE 1');

		// -- left panel (tree)
		$treeId = $this->queryParam('place');
		if ($treeId[0] === 'W')
		{ // warehouse
			$whNdx = intval(substr($treeId, 1));
			array_push ($q, ' AND whp.warehouse = %i', $whNdx);
		}
		elseif ($treeId[0] === 'P')
		{ // place
			$wpNdx = intval(substr($treeId, 1));
			array_push ($q, ' AND whp.ownerPlace = %i', $wpNdx);
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' whp.[title] LIKE %s', '%'.$fts.'%', ' OR whp.[subTitle] LIKE %s', '%'.$fts.'%',
				' OR whp.[id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'whp.', ['[order]', '[id]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->treeParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->treeParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewDetailWHPlace
 * @package E10Doc\Base
 */
class ViewDetailWHPlace extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

/**
 * Class FormWHPlace
 * @package E10Doc\Base
 */
class FormWHPlace extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('title');
			$this->addColumnInput ('subTitle');
			$this->addColumnInput ('id');
			$this->addColumnInput ('order');
			$this->addColumnInput ('warehouse');
			$this->addColumnInput ('ownerPlace');
		$this->closeForm ();
	}
}


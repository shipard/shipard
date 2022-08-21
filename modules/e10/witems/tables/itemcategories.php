<?php

namespace e10\witems;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Utils\Utils;


/**
 * class TableItemCategories
 */
class TableItemCategories extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.witems.itemcategories", "e10_witems_itemcategories", "Kategorie položek");
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['id'] = $this->defaultId($recData);
			$this->app()->db()->query ('UPDATE [e10_witems_itemcategories] SET [id] = %s WHERE [ndx] = %i', $recData['id'], $recData['ndx']);
		}

		$this->checkTree (0, '', 0);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
			$recData['id'] = $this->defaultId($recData);
	}

	public function checkTree ($ownerNdx, $ownerTreeId, $level)
	{
		$treeRows = $this->app()->db->query ("SELECT * from [e10_witems_itemcategories] WHERE [owner] = {$ownerNdx} ORDER BY [order], [fullName]");
		$rowIndex = 1;
		forEach ($treeRows as $row)
		{
			$rowTreeId = $ownerTreeId . sprintf ("%03d", $rowIndex);

			$rowUpdate ['treeLevel'] = $level;
			$rowUpdate ['treeId'] = $rowTreeId;

			$this->app()->db->query ("UPDATE [e10_witems_itemcategories] SET", $rowUpdate, " WHERE [ndx] = %i", $row ['ndx']);
			$this->checkTree ($row ['ndx'], $rowTreeId, $level + 1);

			$rowIndex++;
		}
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData ['id'] = '';
		$recData ['treeId'] = '';

		return $recData;
	}

	public function saveConfig ()
	{
		$itemsCategories = array ();
		$allCats = array ();
		$listCats = array ();

		$itemCatsRows = $this->app()->db->query ("SELECT * from [e10_witems_itemcategories] ORDER BY [treeId]");
		forEach ($itemCatsRows as $ic)
		{
			$allCats [$ic['ndx']] = [
					'fullName' => $ic ['fullName'], 'shortName' => $ic ['shortName'], 'icon' => $ic ['icon'],
					'id' => ($ic ['id'] !== '') ? $ic ['id'] : 'IC'.$ic['ndx'],
					'owner' => $ic ['owner'],
					'askQCR' => $ic ['askQCashRegister'], 'askPCR' => $ic ['askPCashRegister'],
					'state' => $ic['docStateMain'], 'ndx' => $ic['ndx'], 'sortItems' => $ic['sortItems']
			];
		}

		// -- collect query based on properties
		$itemQryRows = $this->app()->db->query (
						"SELECT itemQry.*, propDefs.id as propId, propEnums.id as enumId, itemQry.valueString as valueString from [e10_witems_itemcategoriesqry] as itemQry
							LEFT JOIN e10_base_propdefs AS propDefs ON itemQry.property = propDefs.ndx
							LEFT JOIN e10_base_propdefsenum AS propEnums ON itemQry.valueEnum = propEnums.ndx
							WHERE itemQry.queryType = 0 AND itemQry.property <> 0
							ORDER BY [ndx]");
		forEach ($itemQryRows as $qryRow)
		{
			$onePropQry = array ('prop'=> $qryRow ['propId']);
			if ($qryRow ['enumId'])
				$onePropQry ['value'] = $qryRow ['enumId'];
			else
				$onePropQry ['value'] = $qryRow ['valueString'];

			$allCats [$qryRow ['itemcategory']]['qry'][] = $onePropQry;
		}

		// -- and items types
		$itemQryRows = $this->app()->db->query (
						"SELECT itemQry.*, itemTypes.id as itemTypeId from [e10_witems_itemcategoriesqry] as itemQry
							LEFT JOIN e10_witems_itemtypes AS itemTypes ON itemQry.valueItemType = itemTypes.ndx
							WHERE queryType = 1 AND valueItemType <> 0
							ORDER BY [ndx]");
		forEach ($itemQryRows as $qryRow)
		{
			$onePropQry = array ('itemType'=> $qryRow ['itemTypeId']);
			$allCats [$qryRow ['itemcategory']]['qry'][] = $onePropQry;
		}

		$treeCats = $this->saveConfigCheckTree ($allCats, 0, NULL);

		forEach ($allCats as $oneCat)
			$listCats[$oneCat['ndx']] = $oneCat['path'];

		// save categories to file
		$cfg ['e10']['witems']['categories']['tree'] = $treeCats;
		$cfg ['e10']['witems']['categories']['list'] = $listCats;
		file_put_contents(__APP_DIR__ . '/config/_e10.witems.categories.json', Utils::json_lint(json_encode ($cfg)));
	}

	public function saveConfigCheckTree (&$allCats, $ownerNdx, $ownerCat)
	{
		$cats = array ();
		forEach ($allCats as &$row)
		{
			if ($row ['owner'] != $ownerNdx)
				continue;
			if ($row ['state'] >= 4)
				continue;

			$url = ($ownerCat) ? $ownerCat ['url'] : '';
			$url .= '/' .  $row['id'];

			$oneCat = [
				'id' => $row['id'], 'url' => $url, 'fullName' => $row ['fullName'], 'shortName' => $row ['shortName'],
				'icon' => $row ['icon'], 'ndx' => $row ['ndx'], 'si' => $row['sortItems'],
				'askQCR' => $row ['askQCR'], 'askPCR' => $row ['askPCR'],
			];
			if (isset ($row ['qry']))
				$oneCat ['qry'] = $row ['qry'];
			$oneCat ['cats'] = $this->saveConfigCheckTree ($allCats, $row ['ndx'], $oneCat);
			if (count ($oneCat ['cats']) == 0)
				unset ($oneCat ['cats']);
			$cats [$row ['id']] = $oneCat;
			$row['path'] = str_replace ('/', '.', $url);
		}

		return $cats;
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$topInfo = [['text' => '#'.$recData ['id']]];

		$hdr ['info'][] = ['class' => 'info', 'value' => $topInfo];

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];


		return $hdr;
	}

	function defaultId($recData)
	{
		return
			base_convert(intval($this->app()->cfgItem ('dsid')), 10, 36)
			.'_'.
			base_convert($recData['ndx'], 10, 36);
	}
}


/**
 * class ViewItemCategories
 */
class ViewItemCategories extends TableView
{
	public $queries;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = $item['id'];
		$listItem ['level'] = $item['treeLevel'];

		$props = [];
		if ($item ['order'] != 0)
			$props [] = ['i' => 'sort', 'text' => \E10\nf ($item ['order'], 0)];
		if (count($props))
			$listItem ['i2'] = $props;


		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = "SELECT * from [e10_witems_itemcategories] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [id] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		array_push ($q, ' ORDER BY [treeId] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * class FormItemCategories
 */
class FormItemCategories extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = array ('text' => 'Filtr', 'icon' => 'formFilter');
		$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'system/formSettings');
		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');

		$this->openForm ();
			$this->addColumnInput ("fullName");
			$this->addColumnInput ("shortName");
			//$this->addColumnInput ("id");
			$this->addColumnInput ("icon");
			$this->addColumnInput ("order");
			$this->addColumnInput ('sortItems');

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addList ('rows');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ("owner");
					if ($this->app()->model()->module ('terminals.store') !== FALSE)
						$this->addColumnInput ('askQCashRegister');
						$this->addColumnInput ('askPCashRegister');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId == 'e10.witems.itemcategoriesqry' && $srcColumnId == 'valueEnum')
		{
			return ['property' => $recData ['property']];
		}

		if ($srcTableId == 'e10.witems.itemcategoriesqry' && $srcColumnId == 'property')
		{
			$itemTypes = [];
			$propertyGroups = [];

			$rows = $this->table->db()->query ('SELECT * FROM e10_witems_itemcategoriesqry WHERE queryType = 1 AND itemcategory = %i', $allRecData ['recData']['ndx']);
			foreach ($rows as $r)
				$itemTypes[] = $r['valueItemType'];

			$q = "SELECT links.ndx, links.linkId as linkId, links.srcRecId as propgroup, links.dstRecId as pgNdx from e10_base_doclinks as links " .
					 "WHERE dstTableId = 'e10.base.propgroups' AND srcTableId = 'e10.witems.itemtypes' AND links.srcRecId IN %in";
			$propgroups = $this->table->app()->db->query ($q, $itemTypes);
			foreach ($propgroups as $r)
				$propertyGroups[] = $r['pgNdx'];

			return ['propertyGroups' => implode(',', $propertyGroups)];
		}

		if ($srcTableId == 'e10.witems.itemcategoriesqry' && $srcColumnId == 'valueString')
		{
			$propDef = $this->app()->loadItem ($recData['property'], 'e10.base.propdefs');
			return ['property' => $propDef['id'], 'tableid' => 'e10.witems.items'];
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * class ViewItemCategoriesEditor
 */
class ViewItemCategoriesEditor extends ViewItemCategories
{
	public function init ()
	{
		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		TableView::init();
	}
}

/**
 * @param $cat
 * @param $query
 */
function itemCategoryQuery ($cat, &$query, $itemsSqlName = 'e10_witems_items')
{
	$itemQueryExist = FALSE;
	if (isset ($cat ['qry']))
	{
		$itemTypeIds = [];
		$itemProperties = [];

		forEach ($cat ['qry'] as $q)
		{
			if (isset ($q ['prop']))
				$itemProperties [$q['prop']][] = $q ['value'];

			if (isset ($q ['itemType']))
				$itemTypeIds[] = $q ['itemType'];
		}

		if (count($itemProperties) || count($itemTypeIds))
			$itemQueryExist = TRUE;

		if ($itemQueryExist)
			array_push($query, ' AND (');

		if (count ($itemTypeIds) === 1)
			array_push($query, ' ['.$itemsSqlName.'].[type] = %s', $itemTypeIds[0]);
		else if (count ($itemTypeIds) > 1)
			array_push($query, ' ['.$itemsSqlName.'].[type] IN %in', $itemTypeIds);

		$needAnd = (count ($itemTypeIds) > 0);
		foreach ($itemProperties as $propId => $propValues)
		{
			if ($needAnd)
				array_push($query, 'AND ');
			array_push($query, 'EXISTS (',
									'SELECT ndx FROM e10_base_properties WHERE '.$itemsSqlName.'.ndx = e10_base_properties.recid ');
			if (count($propValues) === 1)
				array_push($query, ' AND [valueString] = %s', $propValues[0]);
			else if (count($propValues) > 1)
				array_push($query, ' AND [valueString] IN %in', $propValues);

			array_push($query, ' AND [property] = %s', $propId, 'AND tableid = %s', 'e10.witems.items', ')');
			$needAnd = TRUE;
		}
	}

	if (isset ($cat['ndx']))
	{
		if ($itemQueryExist)
			array_push($query, ' OR ');
		else
			array_push($query, ' AND ');

		$catsNdxs = [$cat['ndx']];

		if (isset ($cat['cats']) && count($cat['cats']))
		{
			foreach ($cat['cats'] as $cc)
				$catsNdxs[] = $cc['ndx'];
		}
		array_push ($query, ' EXISTS (',
								' SELECT ndx FROM e10_base_doclinks ',
								' WHERE '.$itemsSqlName.'.ndx = srcRecId AND srcTableId = %s AND dstTableId = %s AND e10_base_doclinks.dstRecId IN %in)',
								'e10.witems.items', 'e10.witems.itemcategories', $catsNdxs
		);
	}

	if ($itemQueryExist)
		array_push($query, ')');
}


/**
 * class ViewDetailItemCategory
 */
class ViewDetailItemCategory extends TableViewDetail
{
	protected $itemCategoryCfg = [];

	public function createDetailContent ()
	{
		//$this->createDetailContent_Query();
		$this->createDetailContent_Items();
	}

	public function createDetailContent_Query ()
	{
		$this->itemCategoryCfg = ['ndx' => $this->item['ndx'], 'qry' => []];
		$table = [];

		// -- itemType
		$qt[] = 'SELECT qry.*, itemTypes.fullName as itemTypeFullName, itemTypes.id as itemTypeId FROM e10_witems_itemcategoriesqry as qry ';
		array_push ($qt, ' LEFT JOIN e10_witems_itemtypes AS itemTypes ON qry.valueItemType = itemTypes.ndx');
		array_push ($qt, ' WHERE queryType = 1 AND itemcategory = %i', $this->item['ndx']);

		$itemTypes = [];
		$rows = $this->table->db()->query ($qt);
		foreach ($rows as $r)
		{
			$itemTypes[] = $r['itemTypeFullName'];
			$this->itemCategoryCfg['qry'][] = ['itemType' => $r['itemTypeId']];
		}

		if (count($itemTypes))
			$table[] = ['n' => 'Typ položky', 'v' => implode (', ', $itemTypes)];


		// -- properties
		$itemProperties = [];
		$qp[] = 'SELECT qry.*, propDefs.shortName as propName, propDefs.id as propId FROM e10_witems_itemcategoriesqry as qry ';
		array_push ($qp, ' LEFT JOIN e10_base_propdefs AS propDefs ON qry.property = propDefs.ndx');
		array_push ($qp, ' WHERE queryType = 0 AND itemcategory = %i', $this->item['ndx']);
		$rows = $this->table->db()->query ($qp);
		foreach ($rows as $r)
		{
			$itemProperties[$r['propName']][] = $r['valueString'];
			$this->itemCategoryCfg['qry'][] = ['prop' => $r['propId'], 'value' => $r['valueString']];
		}
		foreach ($itemProperties as $pname => $pvalues)
			$table[] = ['n' => $pname, 'v' => implode (', ', $pvalues)];

		// -- add content
		$this->addContent ([
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => ['n' => 'n', 'v' => 'v'],
			'table' => $table, 'params' => ['hideHeader' => 1, 'forceTableClassXXX' => 'fullWidth'],
			'title' => ['icon' => 'icon-filter', 'text' => 'Filtr']
		]);
	}

	public function createDetailContent_Items ()
	{
		$this->addContentViewer ('e10.witems.items', 'inCategory', ['category' => $this->item ['ndx']]);
	}
}



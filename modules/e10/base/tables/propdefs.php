<?php

namespace E10\Base;

include_once __DIR__ . '/../base.php';

use \E10\Application, \E10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;

class TablePropdefs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.propdefs", "e10_base_propdefs", "Definice vlastností");
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['id'] = $this->defaultId($recData);
			$this->app()->db()->query ('UPDATE [e10_base_propdefs] SET [id] = %s WHERE [ndx] = %i', $recData['id'], $recData['ndx']);
		}
		parent::checkAfterSave2($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
			$recData['id'] = $this->defaultId($recData);
	}

	public function saveConfig ()
	{
		$allProperties = \E10\Base\allPropertiesCfg ($this->app());

		// save types to file
		$cfg ['e10']['base']['properties'] = $allProperties;
		file_put_contents(__APP_DIR__ . '/config/_e10.base.properties.json', utils::json_lint(json_encode ($cfg)));
	}

	public function propertiesConfig ($tableId)
	{
		$allPropGroups = \E10\Base\allPropertiesGroupsCfg ($this->app());
		$typesProperties = [];

		// properties for item types
		$sql = "SELECT links.ndx, links.linkId as linkId, links.srcRecId as typeNdx, props.fullName as fullName, props.id as groupId from e10_base_doclinks as links " .
			"LEFT JOIN e10_base_propgroups as props ON links.dstRecId = props.ndx " .
			"where dstTableId = 'e10.base.propgroups' AND srcTableId = '$tableId'";
		$rows = $this->app()->db->query ($sql);
		forEach ($rows as $r)
		{
			$typeNdx = $r ['typeNdx'];
			$propGroupId = $r ['groupId'];
			if (!isset ($typesProperties [$typeNdx]))
				$typesProperties [$typeNdx] = [];
			if (!isset ($typesProperties [$typeNdx][$propGroupId]))
				$typesProperties [$typeNdx][$propGroupId] = [];

			forEach ($allPropGroups[$propGroupId]['properties'] as $newPropId)
				$typesProperties [$typeNdx][$propGroupId][] = $newPropId;
		}
		return $typesProperties;
	}

	function defaultId($recData)
	{
		return
			base_convert(intval($this->app()->cfgItem ('dsid')), 10, 36)
			.'_'.
			base_convert($recData['ndx'], 10, 36);
	}
} // class TablePropDefs


/* 
 * ViewPropDefs
 * 
 */

class ViewPropDefs extends TableView
{
	public $properties;
	public $itemTypes;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->itemTypes = $this->table->columnInfoEnum ('type');
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
		$listItem ['itemType'] = $item ['type'];

		$listItem ['icon'] = 'tables/e10.base.propdefs';

		return $listItem;
	}

	function decorateRow_X (&$item)
	{
		if ($item ['itemType'] == 'enum')
			$item ['t2'] = $this->properties [$item ['pk']];
		else
			$item ['t2'] = $this->itemTypes [$item ['itemType']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = "SELECT * from [e10_base_propdefs] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [id] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// -- trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		// -- only in some groups?
		if ($this->queryParam('propertyGroups'))
		{
			$pgroups = explode (',', $this->queryParam('propertyGroups'));

			$ndxs = [];
			$qq = [];
			array_push ($qq,
				'SELECT dstRecId from e10_base_doclinks',
				' WHERE dstTableId = %s', 'e10.base.propdefs', ' AND srcTableId = %s', 'e10.base.propgroups',
				' AND linkId = %s', 'e10-base-propgoups-props',
				' AND srcRecId IN %in', $pgroups);
			$rr = $this->db()->query ($qq);
			foreach($rr as $r)
				$ndxs[] = intval($r['dstRecId']);

			array_push ($q, ' AND ndx IN %in', $ndxs);
		}

		array_push ($q, ' ORDER BY [fullName], [ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2_X ()
	{
		if (!count ($this->pks))
			return;
		$pkeys = implode(', ', $this->pks);
		$properties = $this->table->app()->db->query ("SELECT * from [e10_base_propdefsenum] WHERE [property] IN ($pkeys)");

		$ptxt = array ();
		forEach ($properties as $p)
			$ptxt [$p ['property']][] = $p ['fullName'];

		forEach ($ptxt as $propId => $props)
			$this->properties [$propId] = implode (', ', $props);
	}
} // class ViewPropDefs


/**
 * Základní detail Definice vlastností
 *
 */

class ViewDetailPropDefs extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$item = $this->item;
		$info = $item ['fullName'];
		return $this->defaultHedearCode ('x-properties', $item ['fullName'], $info);
	}
}

/**
 * Class ViewPropDefsCombo
 * @package E10\Base
 */
class ViewPropDefsCombo extends ViewPropDefs
{
	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['shortName'];
		//$listItem ['i2'] = $item['id'];
		$listItem ['itemType'] = $item ['type'];

		$listItem ['icon'] = 'tables/e10.base.propdefs';

		return $listItem;
	}
}

/* 
 * FormPropDefs
 * 
 */

class FormPropDefs extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->addColumnInput ("fullName");
			$this->addColumnInput ("shortName");
			$this->addColumnInput ("type");
			//$this->addColumnInput ("id");
			$this->addColumnInput ("multipleValues");
			$this->addColumnInput ('enableNote');
			$this->addColumnInput ('optionaly');

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addList ('rows');
				$this->closeTab ();
			$this->closeTabs ();
		
		$this->closeForm ();
	}	

	public function createHeaderCode ()
	{
		$item = $this->recData;
		$info = '';
		return $this->defaultHedearCode ('x-properties', $item ['fullName'], $info);
	}
} // class FormPropDefs


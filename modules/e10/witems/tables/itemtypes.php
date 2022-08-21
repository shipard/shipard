<?php

namespace e10\witems;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;

/**
 * Class TableItemTypes
 * @package E10\Witems
 */
class TableItemTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.itemtypes', 'e10_witems_itemtypes', 'Typy položek');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
			$recData['id'] = $this->defaultId($recData);
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['id'] = $this->defaultId($recData);
			$this->app()->db()->query ('UPDATE [e10_witems_itemtypes] SET [id] = %s WHERE [ndx] = %i', $recData['id'], $recData['ndx']);
		}
		parent::checkAfterSave2($recData);
	}

	public function saveConfig ()
	{
		$allProperties = \E10\Base\allPropertiesCfg ($this->app());
		$allPropGroups = \E10\Base\allPropertiesGroupsCfg($this->app());

		// -- item types
		$itemTypes = array ();
		$itemTypesMap = array ();
		$itemTypesRows = $this->app()->db->query ('SELECT * from [e10_witems_itemtypes] WHERE docState != 9800 ORDER BY [fullName]');

		$itemTypesProperties = array ();

		foreach ($itemTypesRows as $it)
		{
			$itid = $it ['id'];
			$itemTypes [$itid] = ['ndx' => $it['ndx'], '.text' => $it ['fullName'], 'shortName' => $it ['shortName'], 'icon' => $it ['icon'], 'kind' => $it ['type']];
			if (!utils::dateIsBlank($it['validFrom']))
				$itemTypes [$itid]['validFrom'] = $it['validFrom']->format('Y-m-d');
			if (!utils::dateIsBlank($it['validTo']))
				$itemTypes [$itid]['validTo'] = $it['validTo']->format('Y-m-d');

			$itemTypesMap [$it['ndx']] = $itid;
		}

		// save types to file
		$cfg ['e10']['witems']['types'] = $itemTypes;
		file_put_contents(__APP_DIR__ . '/config/_e10.witems.types.json', utils::json_lint (json_encode ($cfg)));


		// properties for item types
		$sql = "SELECT links.ndx, links.linkId as linkId, links.srcRecId as itemType, props.fullName as fullName, props.id as groupId from e10_base_doclinks as links " .
						"LEFT JOIN e10_base_propgroups as props ON links.dstRecId = props.ndx " .
						"where dstTableId = 'e10.base.propgroups' AND srcTableId = 'e10.witems.itemtypes'";
		$rows = $this->app()->db->query ($sql);
		forEach ($rows as $r)
		{
			if (!isset($itemTypesMap [$r ['itemType']]))
			{
				//error_log ("unknown itemType '{$r ['itemType']}' \n");
				continue;
			}
			$itemTypeId = $itemTypesMap [$r ['itemType']];
			$propGroupId = $r ['groupId'];
			if (!isset ($itemTypesProperties [$itemTypeId]))
				$itemTypesProperties [$itemTypeId] = array ();
			if (!isset ($itemTypesProperties [$itemTypeId][$propGroupId]))
				$itemTypesProperties [$itemTypeId][$propGroupId] = array ();
					//array ('id' => $allPropGroups[$propGroupId]['id'], 'name' => $allPropGroups[$propGroupId]['name'], 'properties' => array ());

			forEach ($allPropGroups[$propGroupId]['properties'] as $newPropId)
				$itemTypesProperties [$itemTypeId][$propGroupId][] = $newPropId;//$allProperties[$newPropId];
		}

		// save types to file
		unset ($cfg);
		$cfg ['e10']['witems']['properties'] = $itemTypesProperties;
		file_put_contents(__APP_DIR__ . '/config/_e10.witems.properties.json', utils::json_lint(json_encode ($cfg)));
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['shortName']];
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
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
 * Class ViewItemTypes
 * @package E10\Witems
 */
class ViewItemTypes extends TableView
{
	public $propgroups;
	var $today = NULL;

	public function init ()
	{
		$this->enableDetailSearch = TRUE;

		$this->today = utils::today();

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		$itemTypes = $this->table->columnInfoEnum ('type');
		$bt [] = array ('id' => '-1', 'title' => 'Vše', 'active' => 1);
		forEach ($itemTypes as $itemTypeId => $itemTypeName)
			$bt [] = array ('id' => $itemTypeId, 'title' => $itemTypeName, 'active' => 0,
											'addParams' => array ('type' => $itemTypeId));
		$this->setBottomTabs ($bt);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = $item['id'];

		$props = [];

		if (!utils::dateIsBlank($item['validFrom']) && $item['validFrom'] > $this->today)
		{
			$listItem['!error'] = 1;
			$props[] = ['text' => 'Od '.utils::datef($item['validFrom']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}
		elseif (!utils::dateIsBlank($item['validFrom']))
			$props[] = ['text' => 'Od '.utils::datef($item['validFrom']), 'icon' => 'system/iconCalendar', 'class' => 'label label-success'];

		if (!utils::dateIsBlank($item['validTo']) && $item['validTo'] < $this->today)
		{
			$listItem['!error'] = 1;
			$props[] = ['text' => 'Do '.utils::datef($item['validTo']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}
		elseif (!utils::dateIsBlank($item['validTo']))
			$props[] = ['text' => 'Do '.utils::datef($item['validTo']), 'icon' => 'system/iconCalendar', 'class' => 'label label-success'];

		if (count($props))
			$listItem['t2'] = $props;

		$types = $this->table->columnInfoEnum ('type');
		$listItem ['i2'] = $types [$item['type']];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	function decorateRow (&$item)
	{
		//$item ['t2'] = $this->propgroups [$item ['pk']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$itemType = intval($this->bottomTabId ());

		$q [] = "SELECT * from [e10_witems_itemtypes] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [id] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- item type
		if ($itemType != -1)
			array_push ($q, " AND [type] = %i", $itemType);

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		array_push ($q, ' ORDER BY [fullName], [ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2_X ()
	{
		if (!count ($this->pks))
			return;
		$pkeys = implode(', ', $this->pks);

		// -- propsgoups
		$sql = "SELECT links.ndx, links.linkId as linkId, links.srcRecId as propgroup, props.shortName as shortName from e10_base_doclinks as links " .
						"LEFT JOIN e10_base_propgroups as props ON links.dstRecId = props.ndx " .
						"where dstTableId = 'e10.base.propgroups' AND srcTableId = 'e10.witems.itemtypes' AND links.srcRecId IN ($pkeys)";
		$propgroups = $this->table->app()->db->query ($sql);

		$ptxt = array ();
		foreach ($propgroups as $r)
			$ptxt [$r ['propgroup']][] = $r ['shortName'];
		forEach ($ptxt as $groupId => $groups)
			$this->propgroups [$groupId] = implode (', ', $groups);
	}
} // class ViewItemTypes


/**
 * Class ViewItemTypesCombo
 * @package E10\Witems
 */
class ViewItemTypesCombo extends ViewItemTypes
{
	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['t2'] = $item['id'];

		$types = $this->table->columnInfoEnum ('type');
		$listItem ['i2'] = $types [$item['type']];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * Class ViewDetailItemTypes
 * @package E10\Witems
 */
class ViewDetailItemTypes extends TableViewDetail
{
	public function createDetailContent ()
	{
		$items = [];

		$q[] = 'SELECT * from [e10_witems_items] WHERE 1 ';
		array_push ($q, ' AND docState IN (1000, 4000, 8000) ');
		array_push ($q, ' AND itemType = %i', $this->item['ndx']);
		array_push ($q, ' LIMIT 0, 304');

		$rows = $this->db()->query ($q);

		$cnt = 0;
		$pks = [];
		foreach ($rows as $r)
		{
			$item = ['t1' => $r['fullName'],
				'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $r['ndx']
			];

			$items[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
			$cnt++;
		}

		$atts = \E10\Base\getAttachments2 ($this->app(), 'e10.witems.items', $pks);
		foreach ($atts as $pk => $imgs)
		{
			$i = $imgs[0];
			$items[$pk]['coverImage'] = 'imgs/-w700/att/'.$i['path'].$i['filename'];
		}

		$title = ['icon' => 'e10-witems-items', 'text'=>'Položky'.' ('.$cnt.')'];
		$this->addContent (['pane' => 'e10-pane', 'title' => $title,
												'type' => 'tiles', 'tiles' => $items, 'class' => 'coverImages']);
	}
}


/**
 * Class FormItemTypes
 * @package E10\Witems
 */
class FormItemTypes extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = array ('text' => 'Základní', 'icon' => 'system/formHeader');

		$this->openForm ();
			$this->addColumnInput ("fullName");
			$this->addColumnInput ("shortName");
			//$this->addColumnInput ("id");
			$this->addColumnInput ("icon");
			$this->addColumnInput ("type");
			$this->addColumnInput ('validFrom');
			$this->addColumnInput ('validTo');

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
} // class FormItemTypes


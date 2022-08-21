<?php

namespace e10pro\property;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;

/**
 * class TableTypes
 */
class TableTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.property.types', 'e10pro_property_types', 'Typy majetku');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		// -- properties
		$tablePropDefs = new \E10\Base\TablePropdefs($this->app());
		$cfg ['e10pro']['property']['properties'] = $tablePropDefs->propertiesConfig($this->tableId());
		file_put_contents(__APP_DIR__ . '/config/_e10pro.property.properties.json', Utils::json_lint(json_encode ($cfg)));
	}
}


/**
 * class ViewTypes
 */
class ViewTypes extends TableView
{
	var $groups;

	public function init ()
	{
		$this->groups = $this->app()->cfgItem('e10pro.property.groups');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$nbt = ['id' => '0', 'title' => 'Vše', 'active' => 1];
		$bt [] = $nbt;

		forEach ($this->groups as $groupId => $g)
		{
			$nbt = ['id' => $groupId, 'title' => $g['sn'], 'active' => 0];
			$bt [] = $nbt;
		}
		$this->setBottomTabs ($bt);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = $item['id'];

		if ($item['propertyKind'] != 99)
		{
			$kinds = $this->table->columnInfoEnum ('propertyKind');
			$listItem ['t2'] = $kinds [$item['propertyKind']];
		}

		//if ($item['debsAccountIdProperty'] !== '')
		//	$listItem ['i2'] = $item['debsAccountIdProperty'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	function decorateRowX (&$item)
	{
		$item ['t2'] = $this->propgroups [$item ['pk']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$bottomTabId = $this->bottomTabId ();

		$q [] = 'SELECT * FROM [e10pro_property_types] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([fullName] LIKE %s OR [id] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		// -- property group
		if ($bottomTabId != '0')
		{
			$g = $this->groups[$bottomTabId];
			array_push($q, ' AND [ndx] IN %in', $g['types']);
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2X ()
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
}


/**
 * class ViewDetailType
 */
class ViewDetailType extends TableViewDetail
{
}


/**
 * class FormType
 */
class FormType extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
			$this->addColumnInput ('propertyKind');

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


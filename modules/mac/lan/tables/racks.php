<?php

namespace mac\lan;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableRacks
 * @package mac\lan
 */
class TableRacks extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.racks', 'mac_lan_racks', 'Racky');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = array ('class' => 'title', 'value' => $recData ['fullName']);

		return $h;
	}
}


/**
 * Class ViewRacks
 * @package mac\lan
 */
class ViewRacks extends TableView
{
	/** @var \mac\lan\TableLans */
	var $tableLans;

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();

		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableLans->setViewerBottomTabs($this);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => $item['id'], 'class' => 'id'];

		if ($item['lanShortName'])
			$listItem ['i2'] = ['text' => $item['lanShortName'], 'icon' => 'icon-sitemap'];
		else
			$listItem ['i2'] = ['text' => '!!!', 'icon' => 'icon-sitemap'];

		if ($item['placeFullName'])
		{
			$listItem['t2'] = ['icon' => 'icon-map-marker', 'text' => $item['placeFullName']];
			if ($item['placeDesc'] !== '')
				$listItem['t2']['suffix'] = $item['placeDesc'];
		}
		elseif ($item['placeDesc'] !== '')
			$listItem['t2'] = ['icon' => 'icon-map-marker', 'text' => $item['placeDesc']];
		else
			$listItem['t2'] = ['icon' => 'icon-map-marker', 'text' => '---'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT racks.*, places.fullName as placeFullName, lans.shortName as lanShortName FROM [mac_lan_racks] AS racks";
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON racks.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON racks.lan = lans.ndx');
		//array_push ($q, ' LEFT JOIN e10pro_property_property AS property ON racks.property = property.ndx');
		array_push ($q, ' WHERE 1');

		$lan = intval($this->bottomTabId());
		if ($lan)
			array_push($q,' AND [racks].[lan] = %i', $lan);

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND (racks.[fullName] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, 'racks.', ['racks.[fullName]', 'racks.[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormDeviceType
 * @package mac\lan
 */
class FormRack extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Senzory', 'icon' => 'icon-eyedropper'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];

		$this->openTabs ($tabs, TRUE);
		$this->openTab ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('id');
			$this->addColumnInput ('rackKind');
			$this->addColumnInput ('place');
			$this->addColumnInput ('placeDesc');
			$this->addColumnInput ('property');
			$this->addColumnInput ('lan');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addListViewer ('sensorsShow', 'formList');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			\E10\Base\addAttachmentsWidget ($this);
		$this->closeTab ();

		$this->closeTabs ();
		$this->closeForm ();
	}
}

/**
 * Class ViewDetailRack
 * @package mac\lan
 */
class ViewDetailRack extends TableViewDetail
{
}


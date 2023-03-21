<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableDevicesGroups
 */
class TableDevicesGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.devicesGroups', 'mac_iot_devicesGroups', 'Skupiny IoT zařízení');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData['docState'] == 9800)
		{ // trash
			//$this->db()->query('DELETE FROM [mac_iot_thingsCfg] WHERE [thing] = %i', $recData['ndx']);
		}
		/*
		if ($recData['docStateMain'] > 1)
		{
			$tcu = new \mac\iot\libs\ThingCfgUpdater($this->app());
			$tcu->init();
			$tcu->updateOne($recData);
		}
		*/
	}

	public function tableIcon ($recData, $options = NULL)
	{
		//if (isset($recData['icon']) && $recData['icon'] !== '')
		//	return $recData['icon'];

		/*
		$thingKind = $this->app()->cfgItem ('mac.iot.things.kinds.'.$recData['thingKind'], NULL);
		if ($thingKind && $thingKind['icon'] !== '')
			return $thingKind['icon'];

		if ($thingKind && $thingKind['thingType'])
		{
			$thingType = $this->app()->cfgItem('mac.iot.things.types.' . $thingKind['thingType'], NULL);
			if ($thingType)
				return $thingType['icon'];
		}
		*/

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewDevicesGroups
 */
class ViewDevicesGroups extends TableView
{
	var $setsTypes;

	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx']];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$t2 = [];
		if ($item['id'] !== '')
			$t2[] = ['text' => $item['id'], 'class' => 'label label-default'];
		if ($item['placeName'])
			$t2[] = ['text' => $item['placeName'], 'class' => 'label label-default', 'icon' => 'tables/e10.base.places'];

		$listItem ['t2'] = $t2;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [devicesGroups].*,';
		array_push ($q, ' places.fullName AS placeName');
		array_push ($q, ' FROM [mac_iot_devicesGroups] AS [devicesGroups]');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON devicesGroups.place = places.ndx');

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [devicesGroups].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [devicesGroups].[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[devicesGroups].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormDeviceGroup
 */
class FormDeviceGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Zařízení', 'icon' => 'formDevices'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('place');
				$this->closeTab ();

				$this->openTab(TableForm::ltNone);
					$this->addListViewer('devices', 'formList');
				$this->closeTab();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDeviceGroup
 */
class ViewDetailDeviceGroup extends TableViewDetail
{
}


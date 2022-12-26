<?php

namespace mac\iot;

use \Shipard\Form\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableDevicesGroupsItems
 */
class TableDevicesGroupsItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.devicesGroupsItems', 'mac_iot_devicesGroupsItems', 'Zařízení sestav');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		/*
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		$thingType = $this->app()->cfgItem ('mac.iot.things.types.'.$recData['thingType'], NULL);

		if ($thingType)
			return $thingType['icon'];
		*/
		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewDevicesGroupsItems
 */
class ViewDevicesGroupsItems extends TableView
{
}

/**
 * Class ViewSetsDevicesFormList
 */
class ViewDevicesGroupsItemsFormList extends \e10\TableViewGrid
{
	var $tableDevicesGroups;
	var $deviceGroupNdx = 0;
	var $deviceGroupRecData = NULL;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->deviceGroupNdx = intval($this->queryParam('devicesGroup'));
		$this->addAddParam('devicesGroup', $this->deviceGroupNdx);

		$this->tableDevicesGroups = $this->app()->table('mac.iot.devicesGroups');
		$this->deviceGroupRecData = $this->tableDevicesGroups->loadItem($this->deviceGroupNdx);

		$g = [
			'device' => 'Zařízení',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'system/iconCogs';

		$listItem ['device'] = $item['iotDeviceName'];
		$listItem ['note'] = '';

		return $listItem;
	}

	function decorateRow (&$item)
	{
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT groupsItems.*, iotDevices.fullName AS iotDeviceName';
		array_push ($q, ' FROM [mac_iot_devicesGroupsItems] AS [groupsItems]');
		array_push ($q, ' LEFT JOIN [mac_iot_devicesGroups] AS devicesGroups ON groupsItems.devicesGroup = devicesGroups.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_devices] AS iotDevices ON groupsItems.iotDevice = iotDevices.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND groupsItems.[devicesGroup] = %i', $this->deviceGroupNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' iotDevices.[fullName] LIKE %s', '%'.$fts.'%',
				' OR iotDevices.[friendlyId] LIKE %s', '%'.$fts.'%',
				' OR iotDevices.[hwId] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY groupsItems.[rowOrder] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
	}
}


/**
 * Class ViewDevicesGroupItemsFormListDetail
 */
class ViewDevicesGroupItemsFormListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'test #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormDeviceGroupItem
 */
class FormDeviceGroupItem extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);

		$this->openForm ();
			//$this->addColumnInput ('rowOrder');
			$this->addColumnInput ('iotDevice');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDeviceGroupItem
 */
class ViewDetailDeviceGroupItem extends TableViewDetail
{
}


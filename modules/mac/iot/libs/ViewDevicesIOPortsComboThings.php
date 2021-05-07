<?php

namespace mac\iot\libs;


use \e10\DbTable, \e10\TableView, e10\utils;


/**
 * Class ViewDevicesIOPortsCombo
 * @package mac\iot\libs
 */
class ViewDevicesIOPortsComboThings extends TableView
{
	var $thingItemTypeNdx = 0;
	var $thingItemTypeCfg = NULL;

	var $enabledValuesKinds = NULL;
	var $enabledTypes = NULL;

	public function init ()
	{
		if (isset ($this->queryParams['thingItemType']))
		{
			$this->thingItemTypeNdx = $this->queryParams['thingItemType'];
			if ($this->thingItemTypeNdx !== '')
				$this->thingItemTypeCfg = $this->app()->cfgItem('mac.iot.things.itemsTypes.'.$this->thingItemTypeNdx, NULL);

			if ($this->thingItemTypeCfg && isset($this->thingItemTypeCfg['ioPortValuesTypes']))
				$this->enabledValuesKinds = $this->thingItemTypeCfg['ioPortValuesTypes'];

			if ($this->thingItemTypeCfg && isset($this->thingItemTypeCfg['ioPortTypes']))
				$this->enabledTypes = $this->thingItemTypeCfg['ioPortTypes'];
		}

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];


		$t1 = $item['fullName'];
		if ($t1 === '')
			$t1 = $item['portId'];

		$listItem ['t1'] = $t1;

		$t2 = [];
		$t2[] = ['text' => $item['deviceName'], 'class' => 'label label-default'];

		$listItem ['t2'] = $t2;



		//$item['portId'];
		//$listItem ['i1'] = 'ABC';

		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ports.*, devices.fullName as deviceName';
		array_push ($q, ' FROM [mac_lan_devicesIOPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_valuesKinds] AS valuesKinds ON ports.valueKind = valuesKinds.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->enabledValuesKinds)
			array_push ($q, ' AND valuesKinds.valueType IN %in', $this->enabledValuesKinds);

		if ($this->enabledTypes)
			array_push ($q, ' AND [ports].[portType] IN %in', $this->enabledTypes);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' ports.[fullName] LIKE %s', '%'.$fts.'%',
				' OR devices.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY ports.[rowOrder], ports.ndx ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}

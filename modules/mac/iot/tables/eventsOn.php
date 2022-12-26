<?php

namespace mac\iot;

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;


/**
 * Class TableEventsOn
 */
class TableEventsOn extends DbTable
{
	CONST
		svtValue = 0,
		svtParam = 1,
		svtTemplate = 2
	;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.eventsOn', 'mac_iot_eventsOn', 'Události Když');
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

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		$iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());

		if ($columnId === 'iotDeviceEvent')
		{
			$events = $iotDevicesUtils->deviceEvents($form->recData['iotDevice'], $form->recData['eventType']);
			if (!$events)
				return [];

			$enum = [];
			foreach ($events as $key => $value)
				$enum[$key] = $key;

			return $enum;
		}

		if ($columnId === 'iotSetupEvent')
		{
			$events = $iotDevicesUtils->iotSetupActions($form->recData['iotSetup']);
			if (!$events)
				return [];

			$enum = [];
			foreach ($events as $key => $value)
				$enum[$key] = $value['fn'];

			return $enum;
		}

		if ($columnId === 'iotDeviceEventValueEnum')
		{
			$events = $iotDevicesUtils->deviceEvents($form->recData['iotDevice'], $form->recData['eventType']);
			if (!$events)
				return [];

			$event = $events[$form->recData['iotDeviceEvent']] ?? NULL;
			if (!$event)
				return [];

			$srcEnum = $event['enum'] ?? $event['enumGet'] ?? NULL;
			if (!$srcEnum)
				return [];

			$enum = [];
			foreach ($srcEnum as $key => $value)
				$enum[$key] = $key;

			return $enum;
		}

		return parent::columnInfoEnum ($columnId, $valueType, $form);
	}

	public function getEventLabels($eventRow, &$dest, $showTitle = FALSE)
	{
		if ($showTitle)
		{
			$dest[] = [
				'text' => $eventRow['fullName'], 'class' => 'e10-bold',
				'docAction' => 'edit', 'pk' => $eventRow['ndx'], 'table' => 'mac.iot.eventsOn'
			];

			if ($eventRow['disabled'])
				$dest[] = ['text' => 'Zakázáno', 'class' => 'label label-danger'];

			$eventType = $this->app()->cfgItem('mac.iot.events.onEventTypes.'.$eventRow['eventType'], NULL);
			if ($eventType)
				$dest[] = ['text' => $eventType['fn'].':', 'class' => 'break e10-small'];
		}

		if ($eventRow['eventType'] === 'deviceAction')
		{
			if ($showTitle)
				$dest[] = [
					'text' => $eventRow['deviceFriendlyId'], 'class' => 'label label-default',
					'docAction' => 'edit', 'pk' => $eventRow['iotDevice'], 'table' => 'mac.iot.devices'
				];
			else
				$dest[] = ['text' => $eventRow['deviceFriendlyId'], 'class' => 'label label-default'];

			$dest[] = ['text' => $eventRow['iotDeviceEvent'], 'class' => 'label label-default'];
			$dest[] = ['text' => ' = ', 'class' => 'label label-default'];
			$dest[] = ['text' => $eventRow['iotDeviceEventValueEnum'], 'class' => 'label label-info'];
		}
		elseif ($eventRow['eventType'] === 'setupAction')
		{
			$dest[] = ['text' => $eventRow['iotSetupId'], 'class' => 'label label-default', 'icon' => 'tables/mac.iot.setups'];
			$dest[] = ['text' => $eventRow['iotSetupEvent'], 'class' => 'label label-info'];
		}
		elseif ($eventRow['eventType'] === 'readerValue')
		{
			$dest[] = ['text' => $eventRow['deviceFriendlyId'], 'class' => 'label label-default'];
			$dest[] = ['text' => $eventRow['iotDeviceEvent'], 'class' => 'label label-default'];
		}
		elseif ($eventRow['eventType'] === 'mqttMsg')
		{
			$dest[] = ['text' => $eventRow['mqttTopic'].':', 'class' => 'label label-default'];
			$dest[] = ['text' => '[\''.$eventRow['mqttTopicPayloadItemId'].'\']', 'class' => 'label label-default'];
			$dest[] = ['text' => ' = ', 'class' => 'label label-default'];
			$dest[] = ['text' => '`'.$eventRow['mqttTopicPayloadValue'].'`', 'class' => 'label label-info'];
		}
		elseif ($eventRow['eventType'] === 'mqttTopic')
		{
			$dest[] = ['text' => $eventRow['mqttTopic'], 'class' => 'label label-default'];
		}
		elseif ($eventRow['eventType'] === 'sensorValue')
		{
			if (!$eventRow['iotSensor'])
			{
				$dest[] = ['text' => 'senzor není vybrán', 'class' => 'label label-warning'];
			}
			else
			{
				$sensorRecData = $this->app()->loadItem($eventRow['iotSensor'], 'mac.iot.sensors');
				if ($sensorRecData)
				{
					$si = $this->app()->cfgItem('mac.data.quantityTypes.' . $sensorRecData['quantityType'] . '.icon', 'x-cog');
					$dest[] = ['text' => $sensorRecData['idName'].':', 'class' => 'label label-default', 'icon' => $si, 'title' => 'Hodnota senzoru '.$sensorRecData['fullName']];


					if ($eventRow['iotSensorValueFromType'] == TableEventsOn::svtValue)
						$dest[] = ['text' => $eventRow['iotSensorValueFrom'], 'class' => 'label label-default'];
					elseif ($eventRow['iotSensorValueFromType'] == TableEventsOn::svtTemplate)
						$dest[] = ['text' => '`'.$eventRow['iotSensorValueFromTemplate'].'`', 'class' => 'label label-default'];
					elseif ($eventRow['iotSensorValueFromType'] == TableEventsOn::svtParam)
					{
						$paramRecData = $this->app()->loadItem($eventRow['iotSensorValueFromParam'], 'mac.iot.params');
						if ($paramRecData)
							$dest[] = ['text' => '#'.$paramRecData['idName'], 'class' => 'label label-default', 'title' => $paramRecData['fullName']];
						else
							$dest[] = ['text' => '#'.'CHYBNÝ PARAMETR', 'class' => 'label label-danger'];
					}

					$dest[] = ['text' => ' až ', 'class' => ''];

					if ($eventRow['iotSensorValueToType'] == TableEventsOn::svtValue)
						$dest[] = ['text' => $eventRow['iotSensorValueTo'], 'class' => 'label label-default'];
					elseif ($eventRow['iotSensorValueToType'] == TableEventsOn::svtTemplate)
						$dest[] = ['text' => '`'.$eventRow['iotSensorValueToTemplate'].'`', 'class' => 'label label-default'];
					elseif ($eventRow['iotSensorValueToType'] == TableEventsOn::svtParam)
					{
						$paramRecData = $this->app()->loadItem($eventRow['iotSensorValueToParam'], 'mac.iot.params');
						if ($paramRecData)
							$dest[] = ['text' => '#'.$paramRecData['idName'], 'class' => 'label label-default', 'title' => $paramRecData['fullName']];
						else
							$dest[] = ['text' => '#'.'CHYBNÝ PARAMETR', 'class' => 'label label-danger'];
					}

					//$dest[] = ['text' => $eventRow['iotSensorValueTo'], 'class' => 'label label-default'];
				}
				else
				{
					$dest[] = ['text' => 'unknown sensor #'.$eventRow['iotSensor'], 'class' => 'label label-warning'];				}
			}
		}
		elseif ($eventRow['eventType'] === 'sensorValueChange')
		{
			if (!$eventRow['iotSensor'])
			{
				$dest[] = ['text' => 'senzor není vybrán', 'class' => 'label label-warning'];
			}
			else
			{
				$sensorRecData = $this->app()->loadItem($eventRow['iotSensor'], 'mac.iot.sensors');
				if ($sensorRecData)
				{
					$si = $this->app()->cfgItem('mac.data.quantityTypes.' . $sensorRecData['quantityType'] . '.icon', 'x-cog');
					$dest[] = ['text' => $sensorRecData['fullName'], 'class' => 'label label-default', 'icon' => $si, 'title' => 'Změna hodnoty senzoru'];
				}
				else
				{
					$dest[] = ['text' => 'unknown sensor #'.$eventRow['iotSensor'], 'class' => 'label label-warning'];				}
			}
		}
	}
}


/**
 * Class ViewSetsDevices
 */
class ViewEventsOn extends TableView
{
}

/**
 * Class ViewEventsOnForm
 */
class ViewEventsOnForm extends TableView
{
	var $dstTableId = '';
	var $dstRecId = 0;
	var $eventsDo = [];

	/** @var $tableEventsDo \mac\iot\TableEventsDo */
	var $tableEventsDo;

	public function init ()
	{
		$this->tableEventsDo = $this->app()->table('mac.iot.eventsDo');

		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;
		$this->toolbarTitle = ['text' => 'Obsluha událostí', 'class' => 'h2 e10-bold'/*, 'icon' => 'system/iconMapMarker'*/];

		$this->dstTableId = $this->queryParam('dstTableId');
		$this->dstRecId = intval($this->queryParam('dstRecId'));

		$this->addAddParam('tableId', $this->dstTableId);
		$this->addAddParam('recId', $this->dstRecId);

		$this->setMainQueries();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'system/iconCogs';

		$listItem ['type'] = [];

		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = [];
		$this->table->getEventLabels($item, $listItem ['t2']);

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->eventsDo [$item ['pk']]))
		{
			foreach ($this->eventsDo [$item ['pk']] as $ed)
			{
				$this->tableEventsDo->getEventLabels($ed, $item ['t2'], ['text' => ' ➜ ', 'class' => 'clear break']);
			}
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT eventsOn.*,';
		array_push ($q, ' iotDevices.friendlyId AS deviceFriendlyId, iotDevices.fullName AS deviceFullName,');
		array_push ($q, ' iotSetups.id AS iotSetupId, iotSetups.fullName AS iotSetupFullName');
		array_push ($q, ' FROM [mac_iot_eventsOn] AS [eventsOn]');
		array_push ($q, ' LEFT JOIN [mac_iot_devices] AS iotDevices ON eventsOn.iotDevice = iotDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_setups] AS iotSetups ON eventsOn.iotSetup = iotSetups.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [eventsOn].[tableId] = %s', $this->dstTableId);
		array_push ($q, ' AND [eventsOn].[recId] = %i', $this->dstRecId);

		// -- fulltext
		/*
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' ports.[fullName] LIKE %s', '%'.$fts.'%',
				' OR devices.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}
		*/
		$this->queryMain ($q, 'eventsOn.', ['[rowOrder]', '[ndx]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- eventsDo
		$q = [];
		$q [] = 'SELECT eventsDo.*,';
		array_push ($q, ' iotDevices.friendlyId AS deviceFriendlyId, iotDevices.fullName AS deviceFullName,');
		array_push ($q, ' devicesGroups.shortName AS devicesGroupName,');
		array_push ($q, ' iotSetups.id AS iotSetupId, iotSetups.fullName AS iotSetupFullName');
		array_push ($q, ' FROM [mac_iot_eventsDo] AS [eventsDo]');
		array_push ($q, ' LEFT JOIN [mac_iot_devices] AS iotDevices ON eventsDo.iotDevice = iotDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_devicesGroups] AS devicesGroups ON eventsDo.iotDevicesGroup = devicesGroups.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_setups] AS iotSetups ON eventsDo.iotSetup = iotSetups.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [eventsDo].[tableId] = %s', 'mac.iot.eventsOn');
		array_push ($q, ' AND [eventsDo].[recId] IN %in', $this->pks);
		array_push ($q, ' AND [eventsDo].[docStateMain] <= %i', 2);
		array_push ($q, ' ORDER BY [eventsDo].[rowOrder], [eventsDo].[ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->eventsDo[$r['recId']][] = $r->toArray();
		}
	}
}


/**
 * Class FormEventOn
 */
class FormEventOn extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('eventType');
					if ($this->recData['eventType'] === 'deviceAction')
					{
						$this->addColumnInput ('iotDevice');
						$this->addColumnInput ('iotDeviceEvent');
						$this->addColumnInput ('iotDeviceEventValueEnum');
					}
					elseif ($this->recData['eventType'] === 'readerValue')
					{
						$this->addColumnInput ('iotDevice');
						$this->addColumnInput ('iotDeviceEvent');
					}
					elseif ($this->recData['eventType'] === 'setupAction')
					{
						$this->addColumnInput ('iotSetup');
						$this->addColumnInput ('iotSetupEvent');
					}
					elseif ($this->recData['eventType'] === 'mqttMsg')
					{
						$this->addColumnInput ('mqttTopic');
						$this->addColumnInput ('mqttTopicPayloadItemId');
						$this->addColumnInput ('mqttTopicPayloadValue');
					}
					elseif ($this->recData['eventType'] === 'sensorValue')
					{
						$this->addColumnInput ('iotSensor');

						$this->addSeparator(self::coH4);
						$this->openRow();
							$this->addColumnInput ('iotSensorValueFromType');
							if ($this->recData['iotSensorValueFromType'] == TableEventsOn::svtValue)
								$this->addColumnInput ('iotSensorValueFrom');
							elseif ($this->recData['iotSensorValueFromType'] == TableEventsOn::svtTemplate)
								$this->addColumnInput ('iotSensorValueFromTemplate');
						$this->closeRow();
						if ($this->recData['iotSensorValueFromType'] == TableEventsOn::svtParam)
							$this->addColumnInput ('iotSensorValueFromParam');

						$this->addSeparator(self::coH4);
						$this->openRow();
							$this->addColumnInput ('iotSensorValueToType');
							if ($this->recData['iotSensorValueToType'] == TableEventsOn::svtValue)
								$this->addColumnInput ('iotSensorValueTo');
							elseif ($this->recData['iotSensorValueToType'] == TableEventsOn::svtTemplate)
								$this->addColumnInput ('iotSensorValueToTemplate');
						$this->closeRow();
						if ($this->recData['iotSensorValueToType'] == TableEventsOn::svtParam)
							$this->addColumnInput ('iotSensorValueToParam');
					}
					elseif ($this->recData['eventType'] === 'sensorValueChange')
					{
						$this->addColumnInput ('iotSensor');
					}

					$this->addViewerWidget ('mac.iot.eventsDo', 'form', ['dstTableId' => 'mac.iot.eventsOn', 'dstRecId' => $this->recData['ndx']], TRUE);
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('rowOrder');
					$this->addColumnInput ('disabled');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailEventOn
 */
class ViewDetailEventOn extends TableViewDetail
{
}


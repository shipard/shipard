<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json, e10\uiutils;
use mac\iot\TableeventsOn;
use mac\iot\Tableparams;


enum OnEventType: int {
	case deviceAction = 0;
	case mqttMsg = 1;
	case readerValue = 2;
	case setupAction = 3;
	case sensorValue = 9;
	case sensorValueChange = 10;
}

/**
 * Class IotEngineCfgCreator
 */
class IotEngineCfgCreator extends Utility
{

	var $siteNdx = 0;

	var \mac\iot\libs\IotDevicesUtils $iotDevicesUtils;
	var $tableIotDevices;

	var $cfg = [];

	public function init()
	{
		$this->iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());
		$this->tableIotDevices = $this->app()->table('mac.iot.devices');

		$this->cfg['params'] = [];
		$this->cfg['topics'] = [];
		$this->cfg['listenTopics'] = [];
		$this->cfg['zigbee2mqttTopics'] = [];
	}

	protected function addTopic($topic, $data)
	{
		if (isset($this->cfg['topics'][$topic]))
		{
			return;
		}

		$this->cfg['topics'][$topic] = $data;
	}

	protected function iotDeviceRecData($deviceNdx)
	{
		$d = $this->tableIotDevices->loadItem($deviceNdx);
		return $d;
	}

	protected function iotDeviceDataModel($deviceNdx)
	{
		return $this->iotDevicesUtils->deviceDataModel($deviceNdx);
	}

	protected function eventOnTopic($eo)
	{
		$t = NULL;
		if ($eo['eventType'] === 'deviceAction')
		{
			$dm = $this->iotDeviceDataModel($eo['iotDevice']);
			if ($dm)
			{
				return $dm['deviceTopic'] ?? NULL;
			}
		}
		elseif ($eo['eventType'] === 'setupAction')
		{
			return $this->iotDevicesUtils->iotSetupTopic($eo['iotSetup']);
		}
		elseif ($eo['eventType'] === 'readerValue')
		{
			$dm = $this->iotDeviceDataModel($eo['iotDevice']);
			if ($dm)
			{
				$property = $dm['properties'][$eo['iotDeviceEvent']] ?? NULL;
				if ($property)
					return $property['valueTopic'] ?? NULL;
			}
		}
		elseif ($eo['eventType'] === 'mqttMsg')
		{
			return $eo['mqttTopic'];
		}
		elseif ($eo['eventType'] === 'mqttTopic')
		{
			return $eo['mqttTopic'];
		}
		elseif ($eo['eventType'] === 'sensorValue')
		{
			$sensorRecData = $this->app()->loadItem($eo['iotSensor'], 'mac.iot.sensors');
			if ($sensorRecData)
				return $sensorRecData['srcMqttTopic'];
		}
		elseif ($eo['eventType'] === 'sensorValueChange')
		{
			$sensorRecData = $this->app()->loadItem($eo['iotSensor'], 'mac.iot.sensors');
			if ($sensorRecData)
				return $sensorRecData['srcMqttTopic'];
		}

		return $t;
	}

	protected function eventDoTopic($ed, $deviceNdx = 0)
	{
		$t = NULL;
		if ($ed['eventType'] === 'setDeviceProperty' || $ed['eventType'] === 'incDeviceProperty' || $ed['eventType'] === 'decDeviceProperty' || $ed['eventType'] === 'assignDeviceProperty')
		{
			$dm = $this->iotDeviceDataModel($deviceNdx ? $deviceNdx : $ed['iotDevice']);
			if ($dm)
			{
				if (isset($dm['deviceTopic']))
					return $dm['deviceTopic'].'/set';
			}
		}
		elseif ($ed['eventType'] === 'sendSetupRequest')
		{
			return $ed['mqttTopic'];
		}
		elseif ($ed['eventType'] === 'sendMqttMsg')
		{
			return $ed['mqttTopic'];
		}

		return $t;
	}

	protected function addEventOn($eo, $additionalField = NULL)
	{
		$topic = $this->eventOnTopic($eo);
		if (!$topic)
		{
			return;
		}

		if (!in_array($topic, $this->cfg['listenTopics']))
			$this->cfg['listenTopics'][] = $topic;

		if (!isset($this->cfg['eventsOn'][$topic]))
		{
			$this->cfg['eventsOn'][$topic] = [];
		}

		$item = [];

		if ($additionalField)
		{
			foreach ($additionalField as $key => $value)
				$item[$key] = $value;
		}

		if ($eo['eventType'] === 'deviceAction')
		{
			$item['type'] = OnEventType::deviceAction;
			$item['dataItem'] = $eo['iotDeviceEvent'];
			$item['dataValue'] = $eo['iotDeviceEventValueEnum'];
		}
		elseif ($eo['eventType'] === 'setupAction')
		{
			$item['type'] = OnEventType::setupAction;
			$item['dataItem'] = 'action';
			$item['dataValue'] = $eo['iotSetupEvent'];
		}
		elseif ($eo['eventType'] === 'readerValue')
		{
			$item['type'] = OnEventType::readerValue;
		}
		elseif ($eo['eventType'] === 'mqttMsg')
		{
			if ($eo['mqttTopicPayloadItemId'] === '')
			{
				$item['type'] = OnEventType::mqttMsg;
				$item['dataItem'] = '';
				$item['dataValue'] = $eo['mqttTopicPayloadValue'];
			}
			else
			{
				$item['type'] = OnEventType::deviceAction;
				$item['dataItem'] = $eo['mqttTopicPayloadItemId'];
				$item['dataValue'] = $eo['mqttTopicPayloadValue'];
			}
		}
		elseif ($eo['eventType'] === 'sensorValue')
		{
			$item['type'] = OnEventType::sensorValue;

			//$item['sensorValueFrom'] = $eo['iotSensorValueFrom'];
			if ($eo['iotSensorValueFromType'] == TableEventsOn::svtValue)
				$item['sensorValueFrom'] = strval($eo['iotSensorValueFrom']);
			elseif ($eo['iotSensorValueFromType'] == TableEventsOn::svtTemplate)
				$item['sensorValueFrom'] = $eo['iotSensorValueFromTemplate'];
			elseif ($eo['iotSensorValueFromType'] == TableEventsOn::svtParam)
			{
				$paramRecData = $this->app()->loadItem($eo['iotSensorValueFromParam'], 'mac.iot.params');
				if ($paramRecData)
					$item['sensorValueFrom'] = '{{params.'.$paramRecData['idName'].'}}';
				else
					$item['sensorValueFrom'] = '{{params.'.'UNKNOWN-PARAM'.'}}';
			}

			//$item['sensorValueTo'] = $eo['iotSensorValueTo'];
			if ($eo['iotSensorValueToType'] == TableEventsOn::svtValue)
				$item['sensorValueTo'] = strval($eo['iotSensorValueTo']);
			elseif ($eo['iotSensorValueToType'] == TableEventsOn::svtTemplate)
				$item['sensorValueTo'] = $eo['iotSensorValueToTemplate'];
			elseif ($eo['iotSensorValueToType'] == TableEventsOn::svtParam)
			{
				$paramRecData = $this->app()->loadItem($eo['iotSensorValueToParam'], 'mac.iot.params');
				if ($paramRecData)
					$item['sensorValueTo'] = '{{params.'.$paramRecData['idName'].'}}';
				else
					$item['sensorValueTo'] = '{{params.'.'UNKNOWN-PARAM'.'}}';
			}
		}
		elseif ($eo['eventType'] === 'sensorValueChange')
		{
			$item['type'] = OnEventType::sensorValueChange;
			$item['sensorValueFrom'] = $eo['iotSensorValueFrom'];
			$item['sensorValueTo'] = $eo['iotSensorValueTo'];
		}

		$item['do'] = [];

		$this->addEventsDo('mac.iot.eventsOn', $eo['ndx'], $item['do'], $topic, $eo);
		$this->cfg['eventsOn'][$topic]['on'][] = $item;
	}

	protected function addEventsDo ($tableId, $recId, &$dst, $srcTopic = NULL, $srcEvent = NULL)
	{
		$q [] = 'SELECT eventsDo.*';
		array_push ($q, ' FROM [mac_iot_eventsDo] AS [eventsDo]');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [eventsDo].[tableId] = %s', $tableId);
		array_push ($q, ' AND [eventsDo].[recId] = %i', $recId);
		array_push ($q, ' AND [eventsDo].[docStateMain] <= %i', 2);
		array_push ($q, ' ORDER BY [eventsDo].[rowOrder], [eventsDo].[ndx]');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$when = NULL;
			if ($r['when'])
				$when = $this->eventWhen($r);

			if ($r['eventType'] === 'sendMqttMsg')
			{
				$destTopic = $this->eventDoTopic($r);
				$pl = ['value' => $r['mqttTopicPayloadValue']];
				if ($when)
					$pl['when'] = $when;
				$dst['sendMqtt'][$destTopic]['payloads'][] = $pl;
				continue;
			}
			if ($r['eventType'] === 'sendSetupRequest')
			{
				$destTopic = $this->iotDevicesUtils->iotSetupTopic($r['iotSetup']);
				$dst['sendSetupRequest'][$destTopic]['actions'][] = ['operation' => 'request', 'request' => $r['iotSetupRequest']];
				continue;
			}

			if ($r['useGroup'])
			{
				$devicesNdxs = [];
				$devicesRows = $this->db()->query('SELECT * FROM [mac_iot_devicesGroupsItems] WHERE devicesGroup = %i', $r['iotDevicesGroup']);
				foreach ($devicesRows as $d)
					$devicesNdxs[] = $d['iotDevice'];
				}
			else
				$devicesNdxs = [$r['iotDevice']];

			foreach ($devicesNdxs as $iotDeviceNdx)
			{
				$destTopic = $this->eventDoTopic($r, $iotDeviceNdx);
				if ($r['eventType'] === 'setDeviceProperty')
				{
					if (!isset($dst['setProperties'][$destTopic]))
						$dst['setProperties'][$destTopic] = ['data' => []];

					$dp = $this->iotDevicesUtils->deviceProperty($iotDeviceNdx, $r['iotDeviceProperty']);
					if ($dp)
					{
						if ($dp['data-type'] === 'binary' || $dp['data-type'] === 'enum')
						{
							$enumSetValue = $dp['enumSet'][$r['iotDevicePropertyValueEnum']] ?? NULL;

							$dst['setProperties'][$destTopic]['data'][$r['iotDeviceProperty']] = ['value' => $this->enumSetValue ($r, $dp, $enumSetValue)];
						}
						elseif ($dp['data-type'] === 'numeric')
							$dst['setProperties'][$destTopic]['data'][$r['iotDeviceProperty']] = ['value' => intval($r['iotDevicePropertyValue'])];

						if ($when)
							$dst['setProperties'][$destTopic]['data'][$r['iotDeviceProperty']]['when'] = $when;
						/*else
							$dst['setProperties'][$destTopic]['data'][$r['iotDeviceProperty']] = $r['iotDevicePropertyValueEnum'];*/
					}
				}
				elseif ($r['eventType'] === 'incDeviceProperty' || $r['eventType'] === 'decDeviceProperty' || $r['eventType'] === 'assignDeviceProperty')
				{
					$srcDeviceDataModel = $this->iotDevicesUtils->deviceDataModel($srcEvent['iotDevice']);
					$srcDeviceAction = $srcDeviceDataModel['properties'][$srcEvent['iotDeviceEvent']] ?? [];
					$srcDeviceActionEnum = $srcDeviceAction['enum'][$srcEvent['iotDeviceEventValueEnum']] ?? [];
					$dstDeviceDataModel = $this->iotDevicesUtils->deviceDataModel($iotDeviceNdx/*$r['iotDevice']*/);

					$dp = $this->iotDevicesUtils->deviceProperty($iotDeviceNdx, $r['iotDeviceProperty']);
					if ($dp)
					{
						$loopId = $srcTopic.'.'.$srcEvent['iotDeviceEvent'];
						if (!isset($dst['stepValues']))
							$dst['stepValues'] = [
								'id' => $loopId,
								'op' => match($r['eventType']) {
									 				'decDeviceProperty' => '-',
													'incDeviceProperty' => '+',
													'assignDeviceProperty' => '=>'
												},
								'properties' => [],
						];

						$dst['stepValues']['properties'][] = [
							'property' => $r['iotDeviceProperty'],
							'value-min' => $dp['value-min'] ?? 0,
							'value-max' => $dp['value-max'] ?? 254,
							'setTopic' => $destTopic,
							'deviceTopic' => $dstDeviceDataModel['deviceTopic'],
						];
					}
				}
			}
		}

		/*
		foreach ($dst as $topicId => &$topicItem)
		{
			if (isset($topicItem['data']))
				$topicItem['payload'] = json_encode($topicItem['data']);
		}
		*/
	}

	protected function eventWhen ($eventRow)
	{
		$when = ['type' => $eventRow['whenType']];

		if ($eventRow['whenType'] === 'sensorValue')
		{
			if (!$eventRow['whenSensor'])
			{
				return NULL;
			}
			else
			{
				$sensorRecData = $this->app()->loadItem($eventRow['whenSensor'], 'mac.iot.sensors');
				if ($sensorRecData)
				{
					$when['sensorId'] = $sensorRecData['idName'];
				}
				else
				{
					return NULL;
				}
			}

			$when['value'] = $eventRow['whenSensorValue'];
		}

		return $when;
	}

	protected function enumSetValue ($eventRecData, $deviceProperty, $enumSetValue)
	{
		if (isset($deviceProperty['valueClass']))
		{
			$o = $this->app()->createObject($deviceProperty['valueClass']);
			if ($o)
			{
				$value = $o->enumValue ($eventRecData, $deviceProperty, $enumSetValue);
				if ($value !== '')
					return $value;
			}
		}

		if ($enumSetValue && isset($enumSetValue['value']))
			return $enumSetValue['value'];
		else
			return $eventRecData['iotDevicePropertyValueEnum'];


		//return 1;
	}

	protected function doSetups()
	{
		$q [] = 'SELECT [iotSetups].*,';
		array_push ($q, ' places.fullName AS placeName');
		array_push ($q, ' FROM [mac_iot_setups] AS [iotSetups]');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON iotSetups.place = places.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND iotSetups.[docStateMain] <= %i', 2);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dt = ['type' => 'setup', 'setupType' => $r['setupType'], 'ndx' => $r['ndx']];
			$setupTopic = $this->iotDevicesUtils->iotSetupTopic($r['ndx']);

			$this->addTopic($setupTopic, $dt);
			$this->addPlace($r['place']);

			$setupsListenTopic = 'shp/setups/#';
			if (!in_array($setupsListenTopic, $this->cfg['listenTopics']))
				$this->cfg['listenTopics'][] = $setupsListenTopic;

			$this->addEventsOn('mac.iot.setups', $r['ndx']);
		}
	}

	protected function doDevices()
	{
		$q [] = 'SELECT [iotDevices].*';
		array_push ($q, ' FROM [mac_iot_devices] AS [iotDevices]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND iotDevices.[docStateMain] <= %i', 2);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ddm = $this->iotDeviceDataModel($r['ndx']);

			$dt = ['type' => 'device', 'ndx' => $r['ndx']];
			if ($r['place'])
				$dt['place'] = $this->iotDevicesUtils->placeTopic($r['place']);
			$this->addTopic($ddm['deviceTopic'], $dt);

			if (isset($ddm['deviceTopic']) && !in_array($ddm['deviceTopic'], $this->cfg['listenTopics']))
			{
				$this->cfg['listenTopics'][] = $ddm['deviceTopic'];
			}

			if (isset($ddm['sensors']))
			{
				foreach ($ddm['sensors'] as $sensorId)
				{
					if (!isset($this->cfg['onSensors'][$ddm['deviceTopic']]))
						$this->cfg['onSensors'][$ddm['deviceTopic']] = ['dataItems' => []];

					$this->cfg['onSensors'][$ddm['deviceTopic']]['dataItems'][] = $sensorId;
				}
			}
		}
	}

	protected function addEventsOn($tableId, $recId, $additionalFields = NULL)
	{
		$qeo = [];
		$qeo [] = 'SELECT eventsOn.*';
		array_push ($qeo, ' FROM [mac_iot_eventsOn] AS [eventsOn]');
		array_push ($qeo, ' WHERE 1');
		array_push ($qeo, ' AND [eventsOn].[tableId] = %s', $tableId);
		array_push ($qeo, ' AND [eventsOn].[recId] = %i', $recId);
		array_push ($qeo, ' AND [eventsOn].[docState] != %i', 9800);
		array_push ($qeo, ' AND [eventsOn].[disabled] = %i', 0);
		$eoRows = $this->db()->query($qeo);
		foreach ($eoRows as $eor)
		{
			$this->addEventOn($eor, $additionalFields);
		}
	}

	protected function doScenes()
	{
		$q [] = 'SELECT [iotScenes].*';
		array_push ($q, ' FROM [mac_iot_scenes] AS [iotScenes]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND iotScenes.[docStateMain] <= %i', 2);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->doScenesAdd($r);
		}
	}

	protected function doScenesAdd($sceneRecData)
	{
		$topic = $this->iotDevicesUtils->sceneTopic($sceneRecData['ndx']);
		$setupTopic = $this->iotDevicesUtils->iotSetupTopic($sceneRecData['setup']);
		$this->addTopic($topic, ['type' => 'scene', 'ndx' => $sceneRecData['ndx'], 'setup' => $setupTopic]);

		$scene = [];
		$scene['setup'] = $setupTopic;

		$this->cfg['topics'][$setupTopic]['scenes'][] = $topic;

		$this->addEventsOn('mac.iot.scenes', $sceneRecData['ndx'], ['setup' => $setupTopic, 'scene' => $topic]);

		$scene['do'] = [];
		$this->addEventsDo('mac.iot.scenes', $sceneRecData['ndx'], $scene['do']);

		$this->cfg['topics'][$topic]['do'] = $scene['do'];
	}

	protected function addPlace($placeNdx)
	{
		if (!$placeNdx)
			return '';

		$topic = $this->iotDevicesUtils->placeTopic($placeNdx);
		//if (isset($this->cfg['places'][$topic]))
		//	return $topic;

		$this->addTopic($topic, ['type' => 'place', 'ndx' => $placeNdx]);

		//$topicListenPlaces = 'shp/places/#';
		//if (!in_array($topicListenPlaces, $this->cfg['listenTopics']))
		//	$this->cfg['listenTopics'][] = $topicListenPlaces;

		return $topic;
	}

	protected function doSensors()
	{
		$cfg = [];

		$q [] = 'SELECT sensors.*, ';
		array_push ($q, ' places.shortName AS placeShorName, places.id AS placeId,');
		array_push ($q, ' racks.fullName AS rackFullName, racks.id AS rackId,');
		array_push ($q, ' devices.fullName AS deviceFullName, devices.id AS deviceId, devices.deviceKind,');
		array_push ($q, ' zones.shortName AS zoneShortName, zones.fullPathId AS zoneId');
		array_push ($q, ' FROM [mac_iot_sensors] AS sensors');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON sensors.place = places.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS racks ON sensors.rack = racks.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON sensors.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_base_zones] AS zones ON sensors.zone = zones.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND sensors.docState = %i', 4000);
		//array_push ($q, ' AND sensors.srcLan = %i', $srcLan);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['type' => 'sensor', 'ndx' => $r['ndx'], 'qt' => $r['quantityType'], 'id' => $r['idName']];

			if ($r['place'])
			{
				$item['place'] = $this->iotDevicesUtils->placeTopic($r['place']);

				if (!isset($this->cfg['topics'][$item['place']]))
					$this->addPlace($r['place']);
			}
			if ($r['zone'])
				$item['zone'] = $r['zone'];
			if ($r['rack'])
				$item['rack'] = $r['rack'];
			//if ($r['device'])
			//	$item['lanDevice'] = $r['lanDevice'];

			$this->addTopic($r['srcMqttTopic'], $item);
		}

		return $cfg;
	}

	protected function doParams()
	{
		$q = [];
		array_push ($q, 'SELECT [params].*');
		array_push ($q, ' FROM [mac_iot_params] AS [params]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [params].docState = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$paramId = $r['idName'];
			$item = ['id' => $paramId, 'type' => $r['paramType']];

			if ($r['paramType'] == TableParams::ptNumber)
				$item['defaultValue'] = $r['defaultValueNum'];
    	elseif ($r['paramType'] == TableParams::ptString)
				$item['defaultValue'] = $r['defaultValueStr'];

			$this->cfg['params'][$paramId] = $item;
		}
	}

	public function run()
	{
		$this->doParams();
		$this->doDevices();
		$this->doSetups();
		$this->doScenes();
		$this->doSensors();

		ksort($this->cfg['topics'], SORT_STRING);
	}
}

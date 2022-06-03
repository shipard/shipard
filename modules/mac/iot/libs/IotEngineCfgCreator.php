<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json, e10\uiutils;

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

		$this->cfg['topics'] = [];
		$this->cfg['listenTopics'] = [];
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

		return $t;
	}

	protected function eventDoTopic($ed, $deviceNdx = 0)
	{
		$t = NULL;
		if ($ed['eventType'] === 'setDeviceProperty' || $ed['eventType'] === 'incDeviceProperty' || $ed['eventType'] === 'decDeviceProperty')
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
			//$item = ['type' => 0, 'dataItem' => $eo['iotDeviceEvent'], 'dataValue' => $eo['iotDeviceEventValueEnum'], 'do' => []];
			$item['type'] = 0;
			$item['dataItem'] = $eo['iotDeviceEvent'];
			$item['dataValue'] = $eo['iotDeviceEventValueEnum'];
		}
		elseif ($eo['eventType'] === 'setupAction')
		{
			//$item = ['type' => 4, 'dataItem' => 'action', 'dataValue' => $eo['iotSetupEvent'], 'do' => []];
			$item['type'] = 3;
			$item['dataItem'] = 'action';
			$item['dataValue'] = $eo['iotSetupEvent'];
		}
		elseif ($eo['eventType'] === 'readerValue')
		{
			//$item = ['type' => 2, 'do' => []];
			$item['type'] = 2;
		}
		elseif ($eo['eventType'] === 'mqttMsg')
		{
			if ($eo['mqttTopicPayloadItemId'] === '')
			{
			//	$item = ['type' => 1, 'dataItem' => '', 'dataValue' => $eo['mqttTopicPayloadValue'], 'do' => []];
				$item['type'] = 1;
				$item['dataItem'] = '';
				$item['dataValue'] = $eo['mqttTopicPayloadValue'];
			}	
			else
			{
				//$item = ['type' => 0, 'dataItem' => $eo['mqttTopicPayloadItemId'], 'dataValue' => $eo['mqttTopicPayloadValue'], 'do' => []];
				$item['type'] = 0;
				$item['dataItem'] = $eo['mqttTopicPayloadItemId'];
				$item['dataValue'] = $eo['mqttTopicPayloadValue'];
			}	
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
			if ($r['eventType'] === 'sendMqttMsg')
			{
				$destTopic = $this->eventDoTopic($r);
				$dst['sendMqtt'][$destTopic]['payload'] = $r['mqttTopicPayloadValue'];
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
							
							$dst['setProperties'][$destTopic]['data'][$r['iotDeviceProperty']] = $this->enumSetValue ($r, $dp, $enumSetValue);
						}	
						elseif ($dp['data-type'] === 'numeric')
							$dst['setProperties'][$destTopic]['data'][$r['iotDeviceProperty']] = intval($r['iotDevicePropertyValue']);
						/*else
							$dst['setProperties'][$destTopic]['data'][$r['iotDeviceProperty']] = $r['iotDevicePropertyValueEnum'];*/
					}
				}
				elseif ($r['eventType'] === 'incDeviceProperty' || $r['eventType'] === 'decDeviceProperty')
				{
					$srcDeviceDataModel = $this->iotDevicesUtils->deviceDataModel($srcEvent['iotDevice']);
					$srcDeviceAction = $srcDeviceDataModel['properties'][$srcEvent['iotDeviceEvent']] ?? [];
					$srcDeviceActionEnum = $srcDeviceAction['enum'][$srcEvent['iotDeviceEventValueEnum']] ?? [];
					$dstDeviceDataModel = $this->iotDevicesUtils->deviceDataModel($iotDeviceNdx/*$r['iotDevice']*/);

					$dp = $this->iotDevicesUtils->deviceProperty($iotDeviceNdx, $r['iotDeviceProperty']);
					if ($dp)
					{
						$loopId = $srcTopic.'.'.$srcEvent['iotDeviceEvent'].'.'.$srcDeviceActionEnum['stopAction'];
						if (!isset($dst['startLoop']))
							$dst['startLoop'] = [
								'id' => $loopId, 
								'stopTopic' => $srcTopic, 
								'stopProperty' => $srcEvent['iotDeviceEvent'], 
								'stopPropertyValue' => $srcDeviceActionEnum['stopAction'], 
								'op' => $r['eventType'] === 'decDeviceProperty' ? '-' : '+',
								'properties' => [], 
						]; 
							
						$dst['startLoop']['properties'][] = [
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
			$item = ['type' => 'sensor', 'ndx' => $r['ndx'], 'qt' => $r['quantityType']];

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

	public function run()
	{
		$this->doDevices();
		$this->doSetups();
		$this->doScenes();
		$this->doSensors();

		ksort($this->cfg['topics'], SORT_STRING);
	}
}

<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json, e10\uiutils;


/**
 * Class IotBoxCfgUpdater
 * @package mac\lan\libs
 */
class IotBoxCfgUpdater extends Utility
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;

	/** @var \mac\lan\TableDevicesIOPorts */
	var $tableIOPorts;

	var $devicesKinds;

	var $lanNdx = 0;
	var $serverNdx = 0;

	var $changes = NULL;

	var $formatVersion = '1';

	var $deviceRecData = NULL;
	var $macDeviceTypeCfg = NULL;
	var $macDeviceSubTypeCfg = NULL;
	var $macDeviceCfg = NULL;
	var $cfgRecData = NULL;
	var $oldConfigVersion = '';

	var $iotBoxCfg = NULL;

	var $iotBoxSensors = NULL;
	var $iotIOPortsThings = NULL;


	public function setLan ($lanNdx)
	{
		$this->lanNdx = $lanNdx;
	}

	public function setServer ($serverNdx)
	{
		$this->serverNdx = $serverNdx;
	}

	public function init()
	{
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableIOPorts = $this->app()->table('mac.lan.devicesIOPorts');
		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
	}

	function getIotBoxCfg($deviceNdx)
	{
		$exist = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgIoTBoxes] WHERE [device] = %i', $deviceNdx)->fetch();
		if ($exist)
		{
			$cfgData = json_decode($exist['iotBoxCfgData'], TRUE);
			if ($cfgData && isset($cfgData['configVersion']))
				$this->oldConfigVersion = $cfgData['configVersion'];
			return $exist->toArray();
		}

		$insert = ['device' => $deviceNdx];
		$this->db()->query('INSERT INTO [mac_lan_devicesCfgIoTBoxes] ', $insert);

		$exist = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgIoTBoxes] WHERE [device] = %i', $deviceNdx)->fetch();
		if ($exist)
			return $exist->toArray();

		return NULL;
	}

	function updateIotBoxCfg($updateData)
	{
		$this->db()->query('UPDATE [mac_lan_devicesCfgIoTBoxes] SET ', $updateData, ' WHERE [ndx] = %i', $this->cfgRecData['ndx']);

		return 1;
	}

	public function checkDeviceIOPorts()
	{
		if (!isset($this->macDeviceSubTypeCfg['fixedIOPorts']))
			return;

		$counter = 0;
		$usedPks = [];
		foreach ($this->macDeviceSubTypeCfg['fixedIOPorts'] as $fixedIoPort)
		{
			$counter++;
			$exist = $this->db()->query('SELECT * FROM [mac_lan_devicesIOPorts] WHERE [device] = %i', $this->deviceRecData['ndx'], ' AND [dpUid] = %i', $fixedIoPort['_dpUid'])->fetch();

			if ($exist)
			{ // UPDATE
				$ioPortItem = $exist->toArray();
				$ioPortCfg = [];

				$ioPortItem['portType'] = $fixedIoPort['type'];
				$ioPortItem['portId'] = $fixedIoPort['portId'];
				$this->checkDeviceIOPorts_FixedIOPort($fixedIoPort,$ioPortCfg, $ioPortItem);
				$ioPortItem['portCfg'] = json::lint($ioPortCfg);

				if (json_encode($exist->toArray()) !== json_encode($ioPortItem))
					$this->db()->query('UPDATE [mac_lan_devicesIOPorts] SET ', $ioPortItem, ' WHERE [ndx] = %i', $exist['ndx']);

				$usedPks[] = $exist['ndx'];
			}
			else
			{ // INSERT
				$ioPortItem = [
					'device' => $this->deviceRecData['ndx'], 'dpUid' => $fixedIoPort['_dpUid'], 'rowOrder' => $counter*100,
					'portType' => $fixedIoPort['type'],
					'portId' => $fixedIoPort['portId'],
				];
				$ioPortCfg = [];
				$this->checkDeviceIOPorts_FixedIOPort($fixedIoPort,$ioPortCfg, $ioPortItem);

				$ioPortItem['portCfg'] = json::lint($ioPortCfg);
				$this->db()->query('INSERT INTO [mac_lan_devicesIOPorts]', $ioPortItem);
				$usedPks[] = $this->db()->getInsertId ();
			}
		}

		// -- DELETE unused rows
		if (count($usedPks))
		{
			$this->db()->query('DELETE FROM [mac_lan_devicesIOPorts] ',
				'WHERE [device] = %i', $this->deviceRecData['ndx'],
				' AND [ndx] NOT IN %in', $usedPks
			);
		}
	}

	public function updateDeviceIOPortsTopics()
	{
		$rows = $this->db()->query('SELECT * FROM [mac_lan_devicesIOPorts] WHERE [device] = %i', $this->deviceRecData['ndx']);
		foreach ($rows as $r)
		{
			$recData = $r->toArray();
			$this->tableIOPorts->makeMqttTopic($recData, $this->deviceRecData['id']);
			if ($r['mqttTopic'] !== $recData['mqttTopic'])
				$this->db()->query('UPDATE [mac_lan_devicesIOPorts] SET mqttTopic = %s', $recData['mqttTopic'], ' WHERE ndx = %i', $r['ndx']);
		}
	}

	function checkDeviceIOPorts_FixedIOPort($fixedIoPortCfg, &$ioPortCfg, &$ioPortItem)
	{
		foreach ($fixedIoPortCfg as $key => $value)
		{
			if ($key === '_cfgColumns')
			{ // "_cfgColumns": {"serial1Speed": "speed", "serial1Mode": "mode"}
				foreach ($value as $cfgColumnId => $ioPortColumnId)
				{
					if (isset($this->macDeviceCfg[$cfgColumnId]))
						$ioPortCfg[$ioPortColumnId] = $this->macDeviceCfg[$cfgColumnId];
				}
				continue;
			}
			if ($key === '_rowColumns')
			{ //
				foreach ($value as $cfgColumnId => $rowColumnId)
				{
					if (isset($this->macDeviceCfg[$cfgColumnId]))
						$ioPortItem[$rowColumnId] = $this->macDeviceCfg[$cfgColumnId];
				}
				continue;
			}
			if ($key === '_portDisabled')
			{
				$ioPortItem['disabled'] = 0;
				foreach ($value as $cfgColumnId => $cfgColumnValue)
				{
					if (isset($this->macDeviceCfg[$cfgColumnId]) && $this->macDeviceCfg[$cfgColumnId] == $cfgColumnValue)
					{
						$ioPortItem['disabled'] = 1;
						break;
					}
				}
				continue;
			}

			if ($key[0] === '_' || $key === 'type' || $key === 'portId')
				continue;

			$ioPortCfg[$key] = $value;
		}
	}

	public function updateOne($deviceRecData)
	{
		$this->deviceRecData = $deviceRecData;
		$this->cfgRecData = $this->getIotBoxCfg($deviceRecData['ndx']);

		$this->macDeviceTypeCfg = $this->app()->cfgItem('mac.devices.types.' . $this->deviceRecData['macDeviceType'], NULL);
		$cfgFileName = __SHPD_MODULES_DIR__ . 'mac/devices/devices/' . $this->macDeviceTypeCfg['cfg'] . '.json';
		$this->macDeviceSubTypeCfg = utils::loadCfgFile($cfgFileName);

		$this->macDeviceCfg = json_decode($this->deviceRecData['macDeviceCfg'], TRUE);
		if (!$this->macDeviceCfg)
			$this->macDeviceCfg = [];

		$this->checkDeviceIOPorts();
		$this->updateDeviceIOPortsTopics();

		$this->createCfg();

		$cfgDataText = json::lint($this->iotBoxCfg);
		$updateData = ['iotBoxCfgData' => $cfgDataText, 'iotBoxCfgDataVer' => sha1($cfgDataText), 'iotBoxCfgDataTimestamp' => new \DateTime()];
		$this->updateIotBoxCfg($updateData);
	}

	function createCfg()
	{
		//$this->loadDeviceSensors();
		//$this->loadIOPortsThings();

		$this->iotBoxCfg = [
			'deviceNdx' => $this->deviceRecData['ndx'],
			'deviceId' => $this->deviceRecData['id'],
			'deviceType' => $this->deviceRecData['macDeviceType'],
		];

		$gpioLayout = $this->tableDevices->gpioLayoutFromRecData(/*$this->macDeviceSubTypeCfg['gpioLayout']*/$this->deviceRecData);

		$usedTopicsPks = [];

		$uioPorts = $this->db()->query('SELECT * FROM [mac_lan_devicesIOPorts] WHERE [device] = %i', $this->deviceRecData['ndx'], ' ORDER BY [rowOrder], ndx');
		foreach ($uioPorts as $uioPort)
		{
			if ($uioPort['disabled'])
				continue;

			$portCfg = json_decode($uioPort['portCfg'], TRUE);
			if (!$portCfg || !count($portCfg))
			{
				continue;
			}
			$ioPortTypeCfg = $this->tableDevices->ioPortTypeCfg($uioPort['portType']);

			$ioPort = ['type' => $uioPort['portType']];
			$ioPortId = $uioPort['portId'];

			$ioPort['portId'] = $ioPortId;

			if (isset($ioPortTypeCfg['useValueKind']) && $ioPortTypeCfg['useValueKind'])
			{
				$ioPortInfo = [
					'iotBoxId' => $this->deviceRecData['id'],
					'ioPortId' => $ioPortId,
				];
				if (isset($this->iotIOPortsThings[$uioPort['ndx']]))
				{
					$thing = $this->iotIOPortsThings[$uioPort['ndx']][0];
					$ioPortInfo['thingId'] = $thing['thingId'];
				}
				$ioPort['valueTopic'] = $uioPort['mqttTopic'];
				//__$usedTopicsPks[] = $this->createCfgTopic($ioPort['valueTopic'], $uioPort['ndx'], 0);
			}
			else
			{
				$topic = $this->tableIOPorts->mqttTopicBegin().'iot-boxes/'.$this->deviceRecData['id'].'/'.$ioPort['portId'];
				if (isset($ioPortTypeCfg['fixedValuesTopic']))
					$ioPort['valueTopic'] = $uioPort['mqttTopic'];
				//__$usedTopicsPks[] = $this->createCfgTopic($topic, $uioPort['ndx'], 1);
			}

			foreach ($portCfg as $key => $value)
			{
				$portTypeCfgColumn = utils::searchArray($ioPortTypeCfg['fields']['columns'], 'id', $key);
				if ($portTypeCfgColumn && isset($portTypeCfgColumn['enumCfgFlags']['type']) && $portTypeCfgColumn['enumCfgFlags']['type'] === 'pin')
				{
					$columnEnabled = uiutils::subColumnEnabled ($portTypeCfgColumn, $portCfg);
					if ($columnEnabled === FALSE)
						continue;

					$pinCfg = isset($gpioLayout['pins'][$value]) ? $gpioLayout['pins'][$value] : NULL;
					if ($pinCfg)
					{
						if (isset($pinCfg['expPortId']))
							$ioPort[$key.'_'.'expPortId'] = $pinCfg['expPortId'];
						$ioPort[$key] = $pinCfg['hwnr'];
					}
				}
				elseif ($portTypeCfgColumn['type'] === 'int')
					$ioPort[$key] = intval($value);
				else
					$ioPort[$key] = $value;
			}

			$this->iotBoxCfg['ioPorts'][] = $ioPort;
		}

		$ver = md5(json_encode($this->iotBoxCfg));
		$this->iotBoxCfg['configVersion'] = $ver;
	}

	/*
	function loadIOPortsThings()
	{
		$this->iotIOPortsThings = [];

		$q [] = 'SELECT items.*,';
		array_push ($q, ' things.fullName AS thingFullName, things.id AS thingId');
		array_push ($q, ' FROM [mac_iot_thingsItems] AS [items]');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesIOPorts] AS ioPorts ON items.ioPort = ioPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_things] AS things ON items.thing = things.ndx');

		//array_push ($q, ' LEFT JOIN [mac_lan_devices] AS ioPortsDevices ON ioPorts.device = ioPortsDevices.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND items.ioPort != %i', 0);
		//array_push ($q, ' AND ioPorts.device = %i', $this->deviceRecData['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ioPortNdx = $r['ioPort'];

			if (!isset($this->iotIOPortsThings[$ioPortNdx]))
				$this->iotIOPortsThings[$ioPortNdx] = [];

			$item = ['thingNdx' => $r['thing'], 'thingId' => $r['thingId']];

			if ($item['thingId'] === '')
				$item['thingId'] = 'iotThing_'.$r['thing'];

			$this->iotIOPortsThings[$ioPortNdx][] = $item;
		}
	}
	*/

	/*
	function loadDeviceSensors()
	{
		$this->iotBoxSensors = [];

		$q[] = 'SELECT * FROM [mac_iot_sensors]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [sensorType] = %i', 1);
		array_push($q, ' AND [iotBoxDevice] = %i', $this->deviceRecData['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$interface = $r['iotBoxInterface'];

			if (!isset($this->iotBoxSensors[$interface]))
			{
				$this->iotBoxSensors[$interface] = ['id' => $r['idName']];
				continue;
			}
		}
	}
	*/
}

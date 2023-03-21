<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json, e10\uiutils;


/**
 * Class IotDeviceCfgUpdaterIotBox
 */
class IotDeviceCfgUpdaterIotBox extends \mac\iot\libs\IotDeviceCfgUpdater
{
	/** @var \mac\iot\TableDevicesIOPorts */
	var $tableIOPorts;
	var $iotBoxCfg = NULL;

	public function init()
	{
		parent::init();
		$this->tableIOPorts = $this->app()->table('mac.iot.devicesIOPorts');
	}

	public function checkDeviceIOPorts()
	{
		$counter = 0;
		$usedPks = [];
		if (isset($this->iotDeviceCfg['fixedIOPorts']))
		{
			foreach ($this->iotDeviceCfg['fixedIOPorts'] as $fpid => $fixedIoPort)
			{
				$counter++;
				$exist = $this->db()->query('SELECT * FROM [mac_iot_devicesIOPorts] WHERE [iotDevice] = %i', $this->iotDeviceRecData['ndx'], ' AND [fpid] = %s', $fpid)->fetch();

				if ($exist)
				{ // UPDATE
					$ioPortItem = $exist->toArray();
					$ioPortCfg = [];

					$ioPortItem['portType'] = $fixedIoPort['type'];
					$ioPortItem['portId'] = $fixedIoPort['portId'];
					$this->checkDeviceIOPorts_FixedIOPort($fixedIoPort, $ioPortCfg, $ioPortItem);
					$ioPortItem['portCfg'] = json::lint($ioPortCfg);

					if (json_encode($exist->toArray()) !== json_encode($ioPortItem))
						$this->db()->query('UPDATE [mac_iot_devicesIOPorts] SET ', $ioPortItem, ' WHERE [ndx] = %i', $exist['ndx']);

					$usedPks[] = $exist['ndx'];
				}
				else
				{ // INSERT
					$ioPortItem = [
						'iotDevice' => $this->iotDeviceRecData['ndx'], 'fpid' => $fpid, 'rowOrder' => $counter*100,
						'portType' => $fixedIoPort['type'],
						'portId' => $fixedIoPort['portId'],
					];
					$ioPortCfg = [];
					$this->checkDeviceIOPorts_FixedIOPort($fixedIoPort,$ioPortCfg, $ioPortItem);

					$ioPortItem['portCfg'] = json::lint($ioPortCfg);
					$this->db()->query('INSERT INTO [mac_iot_devicesIOPorts]', $ioPortItem);
					$usedPks[] = $this->db()->getInsertId ();
				}
			}
		}

		// -- DELETE unused rows
		if (count($usedPks))
		{
			$this->db()->query('DELETE FROM [mac_iot_devicesIOPorts] ',
				'WHERE [iotDevice] = %i', $this->iotDeviceRecData['ndx'], '  AND fpid != %s', '',
				' AND [ndx] NOT IN %in', $usedPks
			);
		}
		else
		{
			$this->db()->query('DELETE FROM [mac_iot_devicesIOPorts] ',
				'WHERE [iotDevice] = %i', $this->iotDeviceRecData['ndx'], '  AND fpid != %s', '');
		}
	}

	public function updateDeviceIOPortsTopics()
	{
		$rows = $this->db()->query('SELECT * FROM [mac_iot_devicesIOPorts] WHERE [iotDevice] = %i', $this->iotDeviceRecData['ndx']);
		foreach ($rows as $r)
		{
			$recData = $r->toArray();
			$this->tableIOPorts->makeMqttTopic($recData, $this->iotDeviceRecData['friendlyId']);
			if ($r['mqttTopic'] !== $recData['mqttTopic'])
				$this->db()->query('UPDATE [mac_iot_devicesIOPorts] SET mqttTopic = %s', $recData['mqttTopic'], ' WHERE ndx = %i', $r['ndx']);
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
					if (isset($this->deviceSettings[$cfgColumnId]))
						$ioPortCfg[$ioPortColumnId] = $this->deviceSettings[$cfgColumnId];
				}
				continue;
			}
			if ($key === '_rowColumns')
			{ //
				foreach ($value as $cfgColumnId => $rowColumnId)
				{
					if (isset($this->deviceSettings[$cfgColumnId]))
						$ioPortItem[$rowColumnId] = $this->deviceSettings[$cfgColumnId];
				}
				continue;
			}
			if ($key === '_portDisabled')
			{
				$ioPortItem['disabled'] = 0;
				foreach ($value as $cfgColumnId => $cfgColumnValue)
				{
					if (isset($this->deviceSettings[$cfgColumnId]) && $this->deviceSettings[$cfgColumnId] == $cfgColumnValue)
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

	function createIotBoxCfg()
	{
		$this->iotBoxCfg = [
			'deviceNdx' => $this->iotDeviceRecData['ndx'],
			'deviceId' => $this->iotDeviceRecData['friendlyId'],
			'deviceType' => $this->iotDeviceRecData['deviceModel'],
		];
		if (isset($this->iotDeviceCfg['fwId']))
			$this->iotBoxCfg['fwId'] = $this->iotDeviceCfg['fwId'];

		$gpioLayout = $this->iotDeviceCfg['io'];

		$usedTopicsPks = [];

		$uioPorts = $this->db()->query('SELECT * FROM [mac_iot_devicesIOPorts] WHERE [iotDevice] = %i', $this->iotDeviceRecData['ndx'], ' ORDER BY [rowOrder], ndx');
		foreach ($uioPorts as $uioPort)
		{
			if ($uioPort['disabled'])
				continue;

			$portCfg = json_decode($uioPort['portCfg'], TRUE);
			if ($portCfg === FALSE || $portCfg === NULL)
			{
				continue;
			}
			$ioPortTypeCfg = $this->tableDevices->ioPortTypeCfg($uioPort['portType']);

			$ioPort = ['type' => $uioPort['portType']];
			$ioPortId = $uioPort['portId'];

			$ioPort['portId'] = $ioPortId;
			if (isset($uioPort['sendAsAction']) && $uioPort['sendAsAction'])
				$ioPort['sendAsAction'] = 1;

			if (isset($ioPortTypeCfg['useValueKind']) && $ioPortTypeCfg['useValueKind'])
			{
				//$ioPort['valueStyle'] = $uioPort['valueStyle'];
				/*$ioPortInfo = [
					'iotBoxId' => $this->deviceRecData['id'],
					'ioPortId' => $ioPortId,
				];
				if (isset($this->iotIOPortsThings[$uioPort['ndx']]))
				{
					$thing = $this->iotIOPortsThings[$uioPort['ndx']][0];
					$ioPortInfo['thingId'] = $thing['thingId'];
				}
				*/
				$ioPort['valueTopic'] = $uioPort['mqttTopic'];
			}
			else
			{
				$topic = $this->tableIOPorts->mqttTopicBegin().'iot-boxes/'.$this->iotDeviceRecData['friendlyId'].'/'.$ioPort['portId'];
				if (isset($ioPortTypeCfg['fixedValuesTopic']))
					$ioPort['valueTopic'] = $uioPort['mqttTopic'];
			}

			foreach ($portCfg as $key => $value)
			{
				$portTypeCfgColumn = utils::searchArray($ioPortTypeCfg['fields']['columns'], 'id', $key);
				if ($portTypeCfgColumn && isset($portTypeCfgColumn['enumCfgFlags']['type']) && $portTypeCfgColumn['enumCfgFlags']['type'] === 'pin')
				{
					$columnEnabled = uiutils::subColumnEnabled ($portTypeCfgColumn, $portCfg);
					if ($columnEnabled === FALSE)
					{
						continue;
					}

					$pinCfg = isset($gpioLayout['pins'][$value]) ? $gpioLayout['pins'][$value] : NULL;
					if ($pinCfg)
					{
						if (isset($pinCfg['expPortId']))
							$ioPort[$key.'_'.'expPortId'] = $pinCfg['expPortId'];
						$ioPort[$key] = $pinCfg['hwnr'];
					}
				}
				elseif ($portTypeCfgColumn['type'] === 'int' || $portTypeCfgColumn['type'] === 'enumInt')
					$ioPort[$key] = intval($value);
				elseif ($portTypeCfgColumn['type'] === 'long')
					$ioPort[$key] = intval($value);
				else
					$ioPort[$key] = $value;
			}

			$this->iotBoxCfg['ioPorts'][] = $ioPort;
		}
	}

	protected function createDataModel()
	{
		$topic = 'shp'.'/iot-boxes/'.$this->iotDeviceRecData['friendlyId'];
		$this->dataModel['deviceTopic'] = $topic;

		$this->dataModel['properties'] = [];

		$uioPorts = $this->db()->query('SELECT * FROM [mac_iot_devicesIOPorts] WHERE [iotDevice] = %i', $this->iotDeviceRecData['ndx'], ' ORDER BY [rowOrder], ndx');
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

			$portTypeId = $uioPort['portType'];

			$ioPortProperties = [
				'ioPortType' => $portTypeId,
			];

			if (isset($ioPortTypeCfg['dataModel']))
				$ioPortProperties = array_merge($ioPortProperties, $ioPortTypeCfg['dataModel']);

			/*
			if ($portTypeId === 'input/binary')
			{
				$this->dataModel['properties'][$uioPort['portId']] = [
					'itemType' => 'action', 'data-type' => 'enum', 'enum' => ['click' => 'click'],
				];
			}
			elseif ($portTypeId === 'control/binary')
			{
				$this->dataModel['properties'][$uioPort['portId']] = [
					'itemType' => 'control', 'data-type' => 'binary'
				];
			}*/


			if (isset($ioPortProperties['eventType']) && $ioPortProperties['eventType'] === 'readerValue')
			{
				$ioPortProperties['valueTopic'] = $uioPort['mqttTopic'];

			}

			$this->dataModel['properties'][$uioPort['portId']] = $ioPortProperties;
		}
	}

	public function update($iotDeviceRecData, &$update)
	{
		parent::update($iotDeviceRecData, $update);

		$this->checkDeviceIOPorts();
		$this->updateDeviceIOPortsTopics();
		$this->createIotBoxCfg();
		$this->createDataModel();

		if ($iotDeviceRecData['deviceTopic'] !== $this->dataModel['deviceTopic'])
			$update['deviceTopic'] = $this->dataModel['deviceTopic'];

		$finalCfg = [
			'dataModel' => $this->dataModel,
			'iotBoxCfg' => $this->iotBoxCfg,
		];

		$newDeviceCfg = ['cfgData' => Json::lint($finalCfg), 'cfgDataTimestamp' => new \DateTime()];
		$newDeviceCfg['cfgDataVer'] = sha1($newDeviceCfg['cfgData']);
		if ($newDeviceCfg['cfgDataVer'] !== $this->cfgRecData['cfgDataVer'])
			$this->iotDevicesUtils->setIotDeviceCfg($this->iotDeviceRecData['ndx'], $newDeviceCfg);
	}
}

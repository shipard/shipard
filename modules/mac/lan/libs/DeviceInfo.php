<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class DeviceInfo
 * @package mac\lan\libs
 */
class DeviceInfo extends Utility
{
	var $deviceNdx = 0;
	var $deviceRecData = NULL;
	var $macDeviceTypeCfg = NULL;
	var $macDeviceSubTypeCfg = NULL;
	var $macDeviceCfg = NULL;
	var $dataSources = [];

	var $info = [];

	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\lan\TableDevicesPorts */
	var $tableDevicesPorts;
	/** @var \mac\data\TableSources */
	var $tableSources;
	/** @var \mac\lan\TableLans */
	var $tableLans;
	var $lanRecData = NULL;
	var $deviceMonitoringBaseUrl = '';
	var $deviceMonitoringNetdataUrl = '';
	var $mainMonitoringNetdataUrl = '';
	var $zigbee2mqttUrl = '';

	public function setDevice($deviceNdx)
	{
		$this->deviceNdx = $deviceNdx;

		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableDevicesPorts = $this->app()->table('mac.lan.devicesPorts');
		$this->tableSources = $this->app()->table('mac.data.sources');
		$this->tableLans = $this->app()->table('mac.lan.lans');

		$this->deviceRecData = $this->tableDevices->loadItem($this->deviceNdx);
		$this->lanRecData = $this->tableLans->loadItem($this->deviceRecData['lan']);

		$mainServerLanControl = $this->tableDevices->loadItem($this->lanRecData['mainServerLanControl']);
		if ($mainServerLanControl)
		{
			$macDeviceCfg = json_decode($mainServerLanControl['macDeviceCfg'], TRUE);
			if ($macDeviceCfg)
			{
				$httpsPort = (isset($macDeviceCfg['httpsPort']) && (intval($macDeviceCfg['httpsPort']))) ? intval($macDeviceCfg['httpsPort']) : 443;
				$baseUrl = 'https://'.$macDeviceCfg['serverFQDN'].':'.$httpsPort.'/';
				$this->deviceMonitoringBaseUrl = $baseUrl;
				$this->deviceMonitoringNetdataUrl = $baseUrl.'netdata/nd-'.Utils::safeChars($this->deviceRecData['id'], TRUE).'-'.$this->deviceRecData['uid'];
				$this->mainMonitoringNetdataUrl = $baseUrl.'netdata/nd-'.Utils::safeChars($mainServerLanControl['id'], TRUE).'-'.$mainServerLanControl['uid'];
			}
		}

		$this->macDeviceTypeCfg = $this->app()->cfgItem('mac.devices.types.' . $this->deviceRecData['macDeviceType'], NULL);
		if ($this->macDeviceTypeCfg === NULL)
		{
			$this->macDeviceSubTypeCfg = [];
		}
		else
		{
			$cfgFileName = __SHPD_MODULES_DIR__ . 'mac/devices/devices/' . $this->macDeviceTypeCfg['cfg'] . '.json';
			$this->macDeviceSubTypeCfg = utils::loadCfgFile($cfgFileName);
		}

		$this->info['recData'] = $this->deviceRecData;

		$macDeviceTypeCfg = $this->tableDevices->macDeviceTypeCfg($this->deviceRecData['macDeviceType']);
		$mdtFamilyCfg = $macDeviceTypeCfg['families'][$this->deviceRecData['mdtFamily']] ?? [];
		$mdtTypeCfg = $mdtFamilyCfg['types'][$this->deviceRecData['mdtType']] ?? [];
		if (isset($mdtTypeCfg['poe']))
		{
			$this->info['poe'] = $mdtTypeCfg['poe'];
			$this->info['cntPoePorts'] = $mdtTypeCfg['cntPoePorts'] ?? 0;
		}

		$this->loadDataSources();

		$this->info['ports'] = [];
		$this->info['cntPortsPhysical'] = 0;
		$this->loadPorts ($this->deviceNdx, $this->info['ports']);
		$this->info['cntPortsTotal'] = count($this->info['ports']);

		$this->info['addresses'] = [];
		$this->loadAddresses($this->deviceNdx, $this->info['addresses']);

		// -- zigbee2mqtt
		$macDeviceCfg = json_decode($this->deviceRecData['macDeviceCfg'], TRUE);
		if ($macDeviceCfg)
		{
			if (isset($macDeviceCfg['zigbee2MQTTEnabled']) && intval($macDeviceCfg['zigbee2MQTTEnabled']))
			{
				$this->zigbee2mqttUrl = $baseUrl.'z2m/z2m-'.Utils::safeChars($this->deviceRecData['id'], TRUE).'-'.$this->deviceRecData['uid'].'/';
			}
			$this->macDeviceCfg = $macDeviceCfg;
			$this->info['macDeviceCfg'] = $this->macDeviceCfg;
		}
	}

	protected function loadDataSources()
	{
		//$deviceUrl
		if ($this->deviceRecData['monitored'])
		{
			$source = ['url' => $this->deviceMonitoringNetdataUrl,];
			$this->dataSources[] = $source;
		}
		else
		{
			$source = ['url' => $this->mainMonitoringNetdataUrl,];
			$this->dataSources[] = $source;
		}
	}

	public function loadPorts ($deviceNdx, &$dstTable)
	{
		$devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');

		$q[] = 'SELECT ports.*, ';
		array_push ($q, ' vlans.num AS vlanNum, vlans.fullName AS vlanName,');
		array_push ($q, ' wallSockets.id AS wallSocketId, wallSocketsPlaces.shortName AS wallSocketPlaceName,');
		array_push ($q, ' connectedDevices.id AS connectedDeviceId, connectedDevices.fullName AS connectedDeviceName, connectedDevices.deviceKind AS connectedDeviceKind,');
		array_push ($q, ' connectedPorts.portNumber AS connectedPortNumber, connectedPorts.portId AS connectedPortId,');
		array_push ($q, ' connectedDevicesRacks.id AS connectedDeviceRackId, connectedDevicesRacks.fullName AS connectedDeviceRackName');
		array_push ($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_vlans] AS vlans ON ports.vlan = vlans.ndx');

		array_push ($q, ' LEFT JOIN [mac_lan_wallSockets] AS wallSockets ON ports.connectedToWallSocket = wallSockets.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS wallSocketsPlaces ON wallSockets.place = wallSocketsPlaces.ndx');

		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS connectedDevices ON ports.connectedToDevice = connectedDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON ports.connectedToPort = connectedPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS connectedDevicesRacks ON connectedDevices.rack = connectedDevicesRacks.ndx');

		array_push ($q, ' WHERE ports.device = %i', $deviceNdx);
		array_push ($q, ' ORDER BY ports.[portNumber], ports.[ndx]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
				'deviceNdx' => $r['device'],
				'num' => $r['portNumber'],
				'portId' => $r['portId'],
				'id' => ['text' => $r['portId'], 'icon' => $this->tableDevicesPorts->tableIcon ($r)],
				'note' => []
			];

			if ($r['vlanName'])
				$item['note'][] = ['text' => $r['vlanName'], 'icon' => 'icon-road', 'class' => 'block'];

			if ($r['note'] !== '')
				$item['note'][] = ['text' => $r['note'], 'class' => 'block'];

			if ($r['connectedTo'] == 0)
			{ // not connected
				$item['connectedTo'][] = ['text' => '', 'class' => ''];
			}
			elseif ($r['connectedTo'] == 1)
			{ // wallSocket
				if ($r['wallSocketId'])
				{
					$item['connectedTo'][] = ['text' => $r['wallSocketId'], 'icon' => 'icon-square-o', 'class' => 'e10-bold'];
					if ($r['wallSocketPlaceName'])
						$item['connectedTo'][] = ['text' => $r['wallSocketPlaceName'], 'icon' => 'system/iconMapMarker', 'class' => ''];

					$this->loadNextPathStep ($item['connectedTo'], [
						'type' => 'wallSocket',
						'srcDevice' => $this->deviceRecData['ndx'],
						'wallSocketNdx' => $r['connectedToWallSocket']
					]);
				}
			}
			elseif ($r['connectedTo'] == 2)
			{ // device/port
				if ($r['connectedDeviceId'])
				{
					$item['connectedTo'][] = [
						'suffix' => $r['connectedDeviceId'], 'text' => $r['connectedDeviceName'],
						'icon' => $devicesKinds[$r['connectedDeviceKind']]['icon'], 'class' => 'e10-bold'
					];

					if ($r['connectedPortNumber'])
					{
						$item['connectedTo'][] = [
							'text' => $r['connectedPortId'], 'suffix' => '#' . $r['connectedPortNumber'],
							'icon' => 'icon-arrow-circle-o-right', 'class' => ''];
					}
					else
						$item['connectedTo'][] = ['text' => '!!!', 'icon' => 'icon-arrow-circle-o-right', 'class' => 'e10-error'];

					/*
					if ($r['connectedDeviceRackId'])
					{
						$item['connectedTo'][] = [
							'text' => $r['connectedDeviceRackName'], 'suffix' => $r['connectedDeviceRackId'],
							'icon' => 'icon-square', 'class' => 'break'];
					}*/

					if ($r['connectedDeviceKind'] == 20)
					{ // media converter
						$this->loadNextPathStep($item['connectedTo'], [
							'type' => 'port',
							'srcDevice' => $this->deviceRecData['ndx'],
							'portNdx' => $r['connectedToPort'],
							'deviceNdx' => $r['connectedToDevice']
						]);
					}
				}
			}
			elseif ($r['connectedTo'] == 3)
			{ // mobile
				$item['connectedTo'][] = ['text' => 'mobilní', 'icon' => 'icon-briefcase', 'class' => 'e10-small'];
			}

			if ($r['portKind'] <= 6)
			{
				$this->info['cntPortsPhysical']++;
				$item['physical'] = 1;
			}
			$dstTable[] = $item;
		}
	}

	function loadNextPathStep (&$dstItem, $connectInfo, $initLevel = 0)
	{
		$devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');

		$q[] = 'SELECT ports.*, ';
		array_push($q, ' devices.id AS deviceId, devices.fullName AS deviceName, devices.deviceKind AS deviceKind');
		array_push($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND ports.device != %i', $connectInfo['srcDevice']);

		if ($connectInfo['type'] === 'wallSocket')
			array_push($q, ' AND ports.connectedToWallSocket = %i', $connectInfo['wallSocketNdx']);
		elseif ($connectInfo['type'] === 'port')
			array_push($q, ' AND ports.connectedToDevice = %i', $connectInfo['deviceNdx']);

		array_push($q, ' ORDER BY ndx');

		$level = $initLevel + 1;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			for ($ii = 0; $ii < $level; $ii++)
			{
				if (!$ii)
					$dstItem[] = ['code' => '<br>'];
				$dstItem[] = ['text' => ' ', 'class' => (($ii === 0) ? 'break' : '')];
			}
			$dstItem[] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => ''];

			if ($r['deviceName'])
			{
				$dstItem[] = [
					'suffix' => $r['deviceId'], 'text' => $r['deviceName'],
					'icon' => $devicesKinds[$r['deviceKind']]['icon'], 'class' => 'e10-off'
				];

				$dstItem[] = [
					'text' => $r['portId'], 'suffix' => '#' . $r['portNumber'],
					'icon' => 'icon-arrow-circle-o-right', 'class' => 'e10-off'];

				if ($r['deviceKind'] == 20)
				{ // media converter
					$this->loadNextPathStep($dstItem, [
						'type' => 'port',
						'srcDevice' => $r['connectedToDevice'],
						'portNdx' => $r['connectedToPort'],
						'deviceNdx' => $r['device'],
					], $level);
				}
			}
		}
	}

	function loadAddresses ($deviceNdx, &$dstTable)
	{
		$addrTypes = $this->app()->cfgItem('mac.lan.ifacesAddrTypes');

		$q[] = 'SELECT ifaces.*, ports.portKind as portKind, ports.portId, ports.mac as portMac, ports.vlan AS portVlan, ';
		array_push ($q, ' ranges.shortName AS rangeName');
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS ports ON ifaces.devicePort = ports.ndx ');
		array_push ($q, ' LEFT JOIN [mac_lan_lansAddrRanges] AS ranges ON ifaces.range = ranges.ndx ');
		array_push ($q, ' WHERE ifaces.device = %i', $deviceNdx);
		array_push ($q, ' ORDER BY ndx');

		$firstIp = '';
		$managementIp = '';
		$managementPortId = '';

		// -- prepare list
		$list = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$portNdx = $r['devicePort'];
			if (!isset($list[$portNdx]))
				$list[$portNdx] = ['portId' => $r['portId'], 'portMac' => $r['portMac'], 'addresses' => []];

			$at = $addrTypes[$r['addrType']];
			$a = ['ip' => $r['ip'], 'rangeName' => $r['rangeName'], 'addrType' => $at['sc'], 'portId' => $r['portId']];
			$list[$portNdx]['addresses'][] = $a;

			if ($firstIp === '')
				$firstIp = $r['ip'];

			if ($r['portVlan'] && $r['portVlan'] === $this->lanRecData['vlanManagement'])
			{
				$managementIp = $r['ip'];
				$managementPortId = $r['portId'];
			}
		}

		if ($managementIp === '')
			$managementIp = $firstIp;

		if ($managementIp !== '')
			$this->info['managementIp'] = $managementIp;

		if ($managementPortId !== '')
			$this->info['managementPortId'] = $r['portId'];

		// -- add to table
		foreach ($list as $portNdx => $portDef)
		{
			$item = ['property' => $portDef['portId'], 'port' => $portDef['portMac'], 'address' => []];

			foreach ($portDef['addresses'] as $a)
			{
				$addrInfo = ['text' => $a['ip'], 'class' => 'block', 'ip' => $a['ip']];
				$addrInfo['suffix'] = $a['rangeName'];
				$addrInfo['prefix'] = $a['addrType'];
				$item['address'][] = $addrInfo;
			}
		}
	}
}

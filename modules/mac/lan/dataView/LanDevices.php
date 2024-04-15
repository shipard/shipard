<?php

namespace mac\lan\dataView;

use e10\Utility;


/**
 * Class LanDevices
 * @package e10pro\lan\dataView
 */
class LanDevices extends \lib\dataView\DataView
{
	var $tablePlaces;
	var $devicesKinds;

	protected function init()
	{
		$this->tablePlaces = $this->app()->table('e10.base.places');

		if (isset($this->requestParams['mainPlace']))
		{
			$list = [];
			$this->tablePlaces->loadParentsPlaces(intval($this->requestParams['mainPlace']), $list);
			$this->requestParams['places'] = $list;
		}

		$this->checkRequestParamsList('deviceKinds');
		$this->checkRequestParamsList('rack');
		$this->checkRequestParamsList('lan');

		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
	}

	protected function loadData()
	{
		$q [] = 'SELECT devices.*, places.fullName as placeFullName, lans.shortName as lanShortName, racks.fullName AS rackName';
		array_push ($q, ' FROM [mac_lan_devices] as devices');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_racks AS racks ON devices.rack = racks.ndx');
		array_push ($q, ' LEFT JOIN e10pro_property_property AS property ON devices.property = property.ndx');

		array_push ($q, ' WHERE 1');

		if (isset($this->requestParams['places']))
			array_push ($q, ' AND devices.place IN %in', $this->requestParams['places']);

		array_push ($q, ' AND devices.docStateMain = %i', 2);

		if (isset($this->requestParams['rack']))
			array_push ($q, ' AND devices.[rack] IN %in', $this->requestParams['rack']);

		if (isset($this->requestParams['lan']))
			array_push ($q, ' AND devices.[lan] IN %in', $this->requestParams['lan']);

		if (isset($this->requestParams['deviceKinds']))
			array_push ($q, ' AND devices.[deviceKind] IN %in', $this->requestParams['deviceKinds']);

		array_push ($q, ' ORDER BY devices.[fullName], devices.[ndx]');

		$t = [];
		$pks = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['id' => $r['id'], 'name' => $r['fullName'], 'typeName' => $r['deviceTypeName']];

			if ($r['placeFullName'])
			{
				if ($r['placeDesc'])
					$item['place'] = ['text' => $r['placeFullName'], 'suffix' => $r['placeDesc']];
				else
					$item['place'] = $r['placeFullName'];
			}

			$dk = $this->devicesKinds[$r['deviceKind']] ?? NULL;
			if ($dk)
				$item['deviceKind'] = $dk['name'] ?? '!!!#'.$r['deviceKind'];
			else
				$item['deviceKind'] = '#'.$r['deviceKind'];
			if ($r['rackName'])
				$item['rack'] = $r['rackName'];

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		$this->loadConnections($pks, $t);
		$this->loadAddresses($pks, $t);

		$this->data['header'] = ['#' => '#', 'id' => 'id', 'deviceKind' => 'Druh', 'name' => 'Název', 'typeName' => 'Typ', 'place' => 'Místo', 'rack' => 'Rack', 'connectedTo' => 'Zapojeno do', 'addr' => 'IP adresa'];
		$this->data['table'] = $t;
	}

	public function loadConnections ($devices, &$data)
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

		array_push ($q, ' WHERE ports.device IN %in', $devices);
		//		array_push ($q, ' AND ports.connectedTo IN %in', [1, 2]);
		array_push ($q, ' AND ports.connectedTo = %i', 2);
		array_push ($q, ' ORDER BY ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = ['num' => $r['portNumber'], 'id' => $r['portId'], 'note' => []];

			if ($r['vlanName'])
				$item['note'][] = ['text' => $r['vlanName'], 'icon' => 'icon-road', 'class' => 'block'];

			if ($r['note'] !== '')
				$item['note'][] = ['text' => $r['note'], 'class' => 'block'];

			if ($r['connectedTo'] == 0)
			{ // not connected
				$item['connectedTo'][] = ['text' => '', 'icon' => 'icon-times', 'class' => ''];
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
						'srcDevice' => $r['device'],
						'wallSocketNdx' => $r['connectedToWallSocket']
					]);
				}
			}
			elseif ($r['connectedTo'] == 2)
			{ // device/port
				if ($r['connectedDeviceId'])
				{
					$item['connectedTo'][] = [
						'prefix' => $r['portId'].':',
						'suffix' => $r['connectedDeviceId'], 'text' => $r['connectedDeviceName'],
						'icon' => $this->devicesKinds[$r['connectedDeviceKind']]['icon'] ?? '', 'class' => 'e10-bold'
					];

					if ($r['connectedPortNumber'])
					{
						$item['connectedTo'][] = [
							'text' => $r['connectedPortId']/*, 'suffix' => '#' . $r['connectedPortNumber']*/,
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
							'srcDevice' => $r['device'],
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

			//$dstTable[] = $item;
			if (isset($data[$r['device']]['connectedTo']))
				$data[$r['device']]['connectedTo'][] = ['code' => '<br>'];
			$data[$r['device']]['connectedTo'][] = $item['connectedTo'];
		}
	}

	function loadNextPathStep (&$dstItem, $connectInfo, $initLevel = 0)
	{
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
				$dstItem[] = ['text' => ' ', 'class' => (($ii === 0) ? 'break' : '')];
			}
			$dstItem[] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => ''];

			if ($r['deviceName'])
			{
				$dstItem[] = [
					'suffix' => $r['deviceId'], 'text' => $r['deviceName'],
					'icon' => $this->devicesKinds[$r['deviceKind']]['icon'], 'class' => 'e10-off'
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

	public function loadAddresses ($devices, &$data)
	{
		$addrTypes = $this->app()->cfgItem('e10pro.lan.ifacesAddrTypes');

		$q[] = 'SELECT ifaces.*, ports.portKind as portKind, ports.portId, ports.mac as portMac, ';
		array_push ($q, ' ranges.shortName AS rangeName');
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS ports ON ifaces.devicePort = ports.ndx ');
		array_push ($q, ' LEFT JOIN [mac_lan_lansAddrRanges] AS ranges ON ifaces.range = ranges.ndx ');
		array_push ($q, ' WHERE ifaces.device IN %in', $devices);
		array_push ($q, ' ORDER BY ndx');

		// -- prepare list
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$portNdx = $r['devicePort'];
			if (!isset($list[$portNdx]))
				$list[$portNdx] = ['portId' => $r['portId'], 'portMac' => $r['portMac'], 'addresses' => []];

			$at = $addrTypes[$r['addrType']] ?? NULL;
			$a = ['prefix' => $r['portId'], 'text' => $r['ip']];
			if ($at && $at['sc'] !== 'F')
				$a['suffix'] = $at['sc'];

			if (isset($data[$r['device']]['addr']))
				$data[$r['device']]['addr'][] = ['code' => '<br>'];
			$data[$r['device']]['addr'][] = $a;
		}

		// -- remove unused portId
		foreach ($data as $deviceNdx => $deviceItem)
		{
			if (isset ($data[$deviceNdx]['addr']) && count($data[$deviceNdx]['addr']) === 1)
				unset ($data[$deviceNdx]['addr'][0]['prefix']);
		}
	}
}






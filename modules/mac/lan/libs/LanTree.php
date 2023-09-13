<?php

namespace mac\lan\libs;

use e10\Utility, \e10\json;


/**
 * Class LanTree
 * @package mac\lan\libs
 */
class LanTree extends Utility
{
	var $lanNdx = 0;
	var $lanRecData = NULL;

	/** @var \mac\lan\TableLans */
	var $tableLans;
	/** @var \mac\lan\TableDevices */
	var $tableDevices;


	var $dataDevices = [];
	var $dataTree = [];

	var $racks = [];


	public function init()
	{
		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
	}

	public function setLan($lanNdx)
	{
		$this->lanNdx = $lanNdx;
		if($this->lanNdx)
			$this->lanRecData = $this->tableLans->loadItem($this->lanNdx);
	}

	function loadCoreTree()
	{
		$lans = [];
		if ($this->lanNdx)
		{
			if (!$this->lanRecData['mainRouter'])
				return;

			$lans[] = ['mainRouter' => $this->lanRecData['mainRouter']];
		}
		else
		{
			$lansRows = $this->db()->query('SELECT mainRouter FROM [mac_lan_lans] WHERE [docState] = %i', 4000,
				' ORDER BY [order], [fullName]');
			foreach ($lansRows as $lr)
			{
				$lans[] = ['mainRouter' => $lr['mainRouter']];
			}
		}

		foreach ($lans as $lan)
		{
			$drd = $this->tableDevices->loadItem($lan['mainRouter']);
			if (!$drd)
				return;

			$dndx = $drd['ndx'];

			$this->dataTree[$dndx] = [
				'deviceNdx' => $dndx,
				'deviceRecData' => $drd,
				'rackNdx' => $drd['rack'],
				'title' => $drd['fullName'],
				'hideFromDR' => $drd['hideFromDR'],
				'items' => []
			];
			$this->loadTree($dndx, $this->dataTree[$dndx]['items'], 0);
		}
	}

	function loadTree($deviceNdx, &$items, $level)
	{
		$q = [];
		array_push ($q, 'SELECT ports.*, ');
		array_push ($q, ' vlans.num AS vlanNum, vlans.fullName AS vlanName,');
		array_push ($q, ' wallSockets.id AS wallSocketId, wallSocketsPlaces.shortName AS wallSocketPlaceName,');
		array_push ($q, ' connectedDevices.id AS connectedDeviceId, connectedDevices.fullName AS connectedDeviceName,',
			' connectedDevices.deviceKind AS connectedDeviceKind, connectedDevices.rack AS connectedRackNdx,',
			' connectedDevices.hideFromDR,');
		array_push ($q, ' connectedPorts.portNumber AS connectedPortNumber, connectedPorts.portId AS connectedPortId,');
		array_push ($q, ' portDevices.id AS portDeviceId,');
		array_push ($q, ' connectedDevicesRacks.id AS connectedDeviceRackId, connectedDevicesRacks.fullName AS connectedDeviceRackName');
		array_push ($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_vlans] AS vlans ON ports.vlan = vlans.ndx');

		array_push ($q, ' LEFT JOIN [mac_lan_wallSockets] AS wallSockets ON ports.connectedToWallSocket = wallSockets.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS wallSocketsPlaces ON wallSockets.place = wallSocketsPlaces.ndx');

		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS connectedDevices ON ports.connectedToDevice = connectedDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS portDevices ON ports.device = portDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON ports.connectedToPort = connectedPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS connectedDevicesRacks ON connectedDevices.rack = connectedDevicesRacks.ndx');

		array_push ($q, ' WHERE ports.device = %i', $deviceNdx);
		array_push ($q, ' AND connectedDevices.docStateMain <= %i', 2);
		if ($level)
			array_push ($q, ' AND ports.portRole = %i', 30);
		array_push ($q, ' ORDER BY connectedDevicesRacks.fullName, portDevices.id, ports.[portNumber], ports.[ndx]');
//		array_push ($q, ' ORDER BY ports.[portNumber], ports.[ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['connectedTo'] != 2)
				continue;
			if (!$r['connectedToDevice'])
				continue;
			if (!$r['connectedToPort'])
				continue;
			if ($r['connectedDeviceKind'] !== 9 && $r['connectedDeviceKind'] !== 14)
				continue;
			if ($r['portRole'] === 20)
				continue;
			//if ($r['portRole'] !== 30)
			//	continue;

			$newItem = [
				'deviceNdx' => $r['connectedToDevice'],
				'deviceId' => $r['portDeviceId'],
				'rackNdx' => $r['connectedRackNdx'],
				'title' => $r['connectedDeviceName'],
				'num' => $r['portNumber'],
				'portIdFrom' => $r['portId'],
				'portIdTo' => $r['connectedPortId'],
				'hideFromDR' => $r['hideFromDR'],
				'items' => [],
			];

			$items[$r['connectedToDevice']] = $newItem;

			if ($r['portRole'] === 30 && ($r['connectedDeviceKind'] === 9 || $r['connectedDeviceKind'] === 14))
				$this->loadTree($r['connectedToDevice'], $items[$r['connectedToDevice']]['items'], $level + 1);
		}
	}

	function loadRacks()
	{
		$q [] = "SELECT racks.*, places.fullName as placeFullName, lans.shortName as lanShortName FROM [mac_lan_racks] AS racks";
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON racks.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON racks.lan = lans.ndx');
		//array_push ($q, ' LEFT JOIN e10pro_property_property AS property ON racks.property = property.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->lanNdx)
			array_push ($q, ' AND racks.lan = %i', $this->lanNdx);

		array_push ($q, ' AND racks.docStateMain <= %i', 2);

		array_push ($q, ' ORDER BY racks.id');

		$rackIdx = 1;
		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$rackNdx = $r['ndx'];
			$rack = [
				'ndx' => $rackNdx, 'title' => $r['fullName'],
				'id' => $r['id'],
				'idx' => $rackIdx
				//'icon' => $this->tableRacks->tableIcon($r),
				];

			$this->racks[$rackNdx] = $rack;
			//$this->racksPks[] = $rackNdx;

			$rackIdx++;
			if ($rackIdx > 9)
				$rackIdx = 1;
		}

		//$this->loadData_RacksSensors();
	}


	public function load()
	{
		$this->loadRacks();
		$this->loadCoreTree();

	}
}

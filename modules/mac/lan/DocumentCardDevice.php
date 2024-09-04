<?php

namespace mac\lan;

use E10\Application, E10\TableForm, E10\Wizard, E10\utils;


/**
 * Class DocumentCardDevice
 * @package mac\lan
 */
class DocumentCardDevice extends \e10\DocumentCard
{
	var $ports = [];
	var $applications = [];
	var $knownPackages = [];
	var $unknownPackages = [];
	var $appLicenses;

	var $tableDevicesPorts;

	public function loadAddresses ($deviceNdx, &$dstTable)
	{
		$addrTypes = $this->app()->cfgItem('mac.lan.ifacesAddrTypes');

		$q[] = 'SELECT ifaces.*, ports.portKind as portKind, ports.portId, ports.mac as portMac, ';
		array_push ($q, ' ranges.shortName AS rangeName');
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS ports ON ifaces.devicePort = ports.ndx ');
		array_push ($q, ' LEFT JOIN [mac_lan_lansAddrRanges] AS ranges ON ifaces.range = ranges.ndx ');
		array_push ($q, ' WHERE ifaces.device = %i', $deviceNdx);
		array_push ($q, ' ORDER BY ndx');

		// -- prepare list
		$list = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$portNdx = $r['devicePort'];
			if (!isset($list[$portNdx]))
				$list[$portNdx] = ['portId' => $r['portId'], 'portMac' => $r['portMac'], 'addresses' => []];

			$at = $addrTypes[$r['addrType']];
			$a = ['ip' => $r['ip'], 'rangeName' => $r['rangeName'], 'addrType' => $at['sc']];
			$list[$portNdx]['addresses'][] = $a;
		}

		// -- add to table
		foreach ($list as $portNdx => $portDef)
		{
			$item = ['property' => $portDef['portId'], 'port' => $portDef['portMac'], 'address' => []];

			foreach ($portDef['addresses'] as $a)
			{
				$addrInfo = ['text' => $a['ip'], 'class' => 'block'];
				$addrInfo['suffix'] = $a['rangeName'];
				$addrInfo['prefix'] = $a['addrType'];
				$item['address'][] = $addrInfo;
			}

			$dstTable[] = $item;
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
				'num' => $r['portNumber'],
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
						'srcDevice' => $this->recData['ndx'],
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

					if ($r['connectedDeviceRackId'])
					{
						$item['connectedTo'][] = [
							'text' => $r['connectedDeviceRackName'], 'suffix' => $r['connectedDeviceRackId'],
							'icon' => 'icon-square', 'class' => 'break'];
					}

					if ($r['connectedDeviceKind'] == 20)
					{ // media converter
						$this->loadNextPathStep($item['connectedTo'], [
							'type' => 'port',
							'srcDevice' => $this->recData['ndx'],
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

	public function createContentHeader ()
	{
		$title = ['icon' => $this->table->tableIcon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);
		$this->addContent('subTitle', ['type' => 'line', 'line' => '#'.$this->recData ['id']]);
	}

	public function createContent_Network ()
	{
		$tableAddresses = [];
		$this->loadAddresses ($this->recData['ndx'], $tableAddresses);
		$titleAddresses = ['text' => 'Síťové adresy', 'icon' => 'system/iconSitemap', 'class' => 'header'];
		$headerAdresses = ['property' => '_Vlastnost', 'port' => 'Port', 'address' => 'Adresy'];

		$tablePorts = [];
		$this->loadPorts($this->recData['ndx'], $tablePorts);
		$titlePorts = ['text' => 'Zapojení portů', 'icon' => 'icon-plug', 'class' => 'header'];
		$headerPorts = ['num' => ' Č.', 'id' => 'ID', 'connectedTo' => 'Zapojeno do', 'note' => 'Upřesnění'];

		$this->addContent ('body',
			[
				'pane' => 'e10-pane e10-pane-table',
				'tables' => [
					[
						'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
						'header' => $headerAdresses, 'table' => $tableAddresses, 'title' => $titleAddresses,
						'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties2col']
					],
					[
						'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
						'header' => $headerPorts, 'table' => $tablePorts, 'title' => $titlePorts
					]
				]
			]);
	}

	public function createContentBody ()
	{
		$this->createContent_Network ();

		$this->addContentAttachments ($this->recData ['ndx']);

		if ($this->recData['property'])
		{
			$tableProperty = $this->app()->table('e10pro.property.property');
			$itemProperty = $tableProperty->loadItem ($this->recData['property']);
			$pdc = new \e10pro\property\DocumentCardProperty ($this->app());
			$pdc->setDocument($tableProperty, $itemProperty);
			$pdc->createContent();

			$title = ['text' => 'Evidence majetku', 'icon' => 'icon-cube', 'class' => 'header'];
			$pdc->content['body'][0]['title'] = $title;
			foreach ($pdc->content['body'] as $cp)
				$this->addContent('body', $cp);
		}
	}

	public function createContent ()
	{
		$this->tableDevicesPorts = $this->app()->table('mac.lan.devicesPorts');

		$this->createContentHeader ();
		$this->createContentBody ();
	}
}

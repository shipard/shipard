<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class MacsUtils
 * @package mac\lan\libs
 */
class MacsUtils extends Utility
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\lan\TableDevicesPorts */
	var $tableDevicesPorts;
	/** @var \mac\lan\TableWallSockets */
	var $tableWallSockets;

	function init()
	{
		$this->tableDevices = $this->app()->table ('mac.lan.devices');
		$this->tableDevicesPorts = $this->app()->table ('mac.lan.devicesPorts');
		$this->tableWallSockets = $this->app()->table ('mac.lan.wallSockets');
	}

	public function loadMacs($macs, $labelClass = FALSE)
	{
		$lc = ($labelClass) ? $labelClass : 'e10-block';
		$this->init();

		$res = [
			'table' => [], 'header' => ['#' => '#', 'mac' => 'MAC', 'devices' => 'Zařízení'],
			'macs' => [],
		];

		$q [] = 'SELECT ports.*,';
		array_push($q, ' devices.id AS deviceId, devices.fullName AS deviceName, devices.deviceKind, devices.deviceType');
		array_push($q, ' FROM [mac_lan_devicesPorts] AS [ports]');
		array_push ($q,' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ports.[mac] IN %in', $macs);
		array_push($q, ' ORDER BY ports.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$mac = strtolower($r['mac']);
			$res['macs'][$mac][] = [
				'deviceNdx' => $r['device'], 'deviceId' => $r['deviceId'], 'deviceName' => $r['deviceName'],
				'deviceIcon' => $this->tableDevices->tableIcon($r),
				'portNdx' => $r['ndx'], 'portId' => $r['portId'], 'portNumber' => $r['portNumber'],
				'connectedTo' => $r['connectedTo'], 'connectedToWallSocket' => $r['connectedToWallSocket']
				];
		}

		// -- create table
		foreach ($macs as $mmac)
		{
			$mac = strtolower($mmac);
			$item = ['mac' => $mac];
			if (isset($res['macs'][$mac]))
			{
				$item['devices'] = [];
				foreach ($res['macs'][$mac] as $device)
				{
					$l = ['text' => $device['deviceName'], 'suffix' => $device['portId'], 'icon' => $device['deviceIcon'], 'class' => $lc];

					if (count($item['devices']))
						$l['class'] .= ' e10-error';

					$item['devices'][] = $l;
					$res['labels'][$mac][] = $l;
				}
			}

			$res['table'][] = $item;
		}

		return $res;
	}

	public function loadDevicesPorts($devices, $labelClass = FALSE)
	{
		$this->init();

		$res = [
			'table' => [], 'header' => ['#' => '#', 'device' => 'Zařízení', 'port' => 'Port'],
			'macs' => [],
		];


		foreach ($devices as $dp)
		{
			$device = $this->tableDevices->loadItem($dp['d']);
			$port = $this->db()->query('SELECT * FROM [mac_lan_devicesPorts]',
				' WHERE [device] = %i', $dp['d'],
				' AND [portNumber] = %i', $dp['p'],
				' AND [portKind] < 10')->fetch();


			$ld = ['text' => $device['fullName'], 'icon' => $this->tableDevices->tableIcon($device), 'class' => ''];
			$item = ['device' => $ld];

			if ($port)
			{
				$lp = ['text' => $port['portId'], 'suffix' => '#'.$port['portNumber'], 'icon' => $this->tableDevicesPorts->tableIcon($port), 'class' => ''];
				$item['port'] = $lp;
			}
			else
			{
				$lp = ['text' => '#'.$dp['p'], 'icon' => 'icon-question-circle', 'class' => 'e10-off'];
				$item['port'] = $lp;
			}

			$res['table'][] = $item;
		}

		return $res;
	}

	function checkConnectToHint($recData)
	{
		if ($recData['portNumber'] < 1)
			return NULL;

		$macs = json_decode($recData['macs'], TRUE);
		if (!$macs || !count($macs))
			return NULL;

		$srcDevice = $this->tableDevices->loadItem($recData['device']);
		$srcPort = $this->db()->query('SELECT * FROM [mac_lan_devicesPorts]',
			' WHERE [device] = %i', $recData['device'],
			' AND [portNumber] = %i', $recData['portNumber'],
			' AND [portKind] < 10')->fetch();

		if (!$srcDevice || !$srcPort)
			return NULL;

		if ($srcPort['connectedTo'] == 2)
			return NULL; // is connected to port

		$devices = $this->loadMacs($macs);
		if (count($devices['macs']) !== 1)
			return NULL;

		$connectedMac = key($devices['macs']);
		if (count($devices['macs'][$connectedMac]) !== 1)
			return NULL;

		$dstPort = $this->tableDevicesPorts->loadItem($devices['macs'][$connectedMac][0]['portNdx']);
		if ($srcPort['connectedTo'] == 0 && !$dstPort)
			return NULL;
		if ($srcPort['connectedTo'] == 0 && $dstPort['connectedTo'] !== 0)
			return NULL;

		$dstWallSocket = NULL;
		if ($srcPort['connectedTo'] == 1)
		{
			if (!$srcPort['connectedToWallSocket'])
				return NULL;
			if ($devices['macs'][$connectedMac][0]['connectedTo'] != 0)
				return NULL;
			if ($devices['macs'][$connectedMac][0]['connectedToWallSocket'] != 0)
				return NULL;

			$dstWallSocket = $this->tableWallSockets->loadItem($srcPort['connectedToWallSocket']);
		}

		if ($srcPort['connectedTo'] == 0)
		{ // not connected
			$res = [
				'title' => [['text' => 'Návrh na propojení', 'icon' => 'icon-exchange', 'class' => 'h2 block bb1 mb1']],
				'info' => [
						['text' => 'Vypadá to, že tato dvě zařízení by měla být propojena:', 'class' => 'block'],
						['text' => $srcDevice['fullName'], 'suffix' => $srcPort['portId'], 'icon' => $this->tableDevices->tableIcon($srcDevice), 'class' => 'block padd5'],
						['text' => 'a', 'class' => 'pl1'],
						['text' => $devices['macs'][$connectedMac][0]['deviceName'], 'suffix' => $devices['macs'][$connectedMac][0]['portId'], 'icon' => $devices['macs'][$connectedMac][0]['deviceIcon'], 'class' => 'block padd5'],
					],
			];

			$res['title'][] = [
				'type' => 'action', 'action' => 'addwizard',
				'text' => 'Propojit', 'data-class' => 'mac.lan.libs.ConnectDeviceWizard', 'icon' => 'icon-exchange',
				'class' => 'pull-right',
				'data-addparams' => 'srcDevice='.$srcDevice['ndx'].'&srcPort='.$srcPort['ndx'].
														'&connectedTo=2'.'&dstDevice='.$devices['macs'][$connectedMac][0]['deviceNdx'].
														'&dstPort='.$devices['macs'][$connectedMac][0]['portNdx']
			];

			return $res;
		}
		elseif ($srcPort['connectedTo'] == 1)
		{ // connected to socket
			$res = [
				'title' => [['text' => 'Návrh na zapojení do zásuvky', 'icon' => 'icon-exchange', 'class' => 'h2 block bb1 mb1']],
				'info' => [
					['text' => 'Vypadá to, že zařízení by mělo být zapojeno do zásuvky:', 'class' => 'block'],
					['text' => $devices['macs'][$connectedMac][0]['deviceName'], 'suffix' => $devices['macs'][$connectedMac][0]['portId'], 'icon' => $devices['macs'][$connectedMac][0]['deviceIcon'], 'class' => 'block padd5'],
					['text' => 'do', 'class' => 'pl1'],
					['text' => $dstWallSocket['id'], 'icon' => $this->tableWallSockets->tableIcon($dstWallSocket), 'class' => 'block padd5'],
				],
			];

			$res['title'][] = [
				'type' => 'action', 'action' => 'addwizard',
				'text' => 'Zapojit', 'data-class' => 'mac.lan.libs.ConnectSocketWizard', 'icon' => 'icon-exchange',
				'class' => 'pull-right',
				'data-addparams' => 'wallSocket='.$dstWallSocket['ndx'].
					'&connectedTo=1'.'&dstDevice='.$devices['macs'][$connectedMac][0]['deviceNdx'].
					'&dstPort='.$devices['macs'][$connectedMac][0]['portNdx']
			];

			return $res;
		}

		return NULL;
	}
}

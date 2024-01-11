<?php

namespace mac\lan\dataView;

use e10\Utility;


/**
 * Class WallSockets
 * @package e10pro\lan\dataView
 */
class WallSockets extends \lib\dataView\DataView
{
	var $tablePlaces;

	protected function init()
	{
		$this->tablePlaces = $this->app()->table('e10.base.places');

		if (isset($this->requestParams['mainPlace']))
		{
			$list = [];
			$this->tablePlaces->loadParentsPlaces(intval($this->requestParams['mainPlace']), $list);
			$this->requestParams['places'] = $list;
		}
	}

	protected function loadData()
	{
		$q [] = 'SELECT ws.*, places.fullName AS placeFullName, lans.shortName AS lanShortName, racks.fullName AS rackName';
		array_push ($q, ' FROM [mac_lan_wallSockets] AS ws');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON ws.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON ws.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_racks AS racks ON ws.rack = racks.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ws.docStateMain = %i', 2);

		if (isset($this->requestParams['places']))
			array_push ($q, ' AND ws.place IN %in', $this->requestParams['places']);

		array_push ($q, ' ORDER BY ws.[idOrder], ws.[ndx]');

		$t = [];
		$pks = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['id' => $r['id']];

			if ($r['placeFullName'])
				$item['place'] = $r['placeFullName'];
			if ($r['rackName'])
				$item['rack'] = $r['rackName'];

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		$this->loadConnections($pks, $t);

		$this->data['header'] = ['#' => '#', 'id' => 'id', 'place' => 'Místo', 'rack' => 'Rack', 'connectedTo' => 'Zapojeno do'];
		$this->data['table'] = $t;
	}

	public function loadConnections ($pks, &$data)
	{
		if (!count ($pks))
			return;

		$devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');

		$q[] = 'SELECT ports.*, ';
		array_push($q, ' devices.id AS deviceId, devices.fullName AS deviceName, devices.deviceKind AS deviceKind');
		array_push($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND ports.connectedToWallSocket IN %in', $pks);

		array_push($q, ' ORDER BY devices.deviceKind DESC');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['deviceName'])
			{
				$first = !isset($data[$r['connectedToWallSocket']]['connectedTo']);

				$dstItem = [];

				if ($first)
					$dstItem[] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => ''];
				else
				{
					$dstItem[] = ['code' => '<br>'];
					$dstItem[] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => 'break'];
				}

				$dstItem[] = [
					'text' => $r['deviceName'], 'suffix' => $r['deviceId'].'_'.$r['deviceKind'],
					'icon' => $devicesKinds[$r['deviceKind']]['icon'] ?? 'system/iconWarning', 'class' => ''
				];

				$dstItem[] = [
					'text' => $r['portId'], /*'suffix' => '#' . $r['portNumber'],*/
					'icon' => 'iconArrowAltRightCircle', 'class' => ''
				];

				$data[$r['connectedToWallSocket']]['connectedTo'][] = $dstItem;
			}
		}
	}

}






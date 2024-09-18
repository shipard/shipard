<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class SensorsAndControlsEngine
 * @package mac\iot\libs
 */
class SensorsAndControlsEngine extends Utility
{
	var $wss = [];
	var $scPlacements = [];
	var $mainMenu = [];
	var $items = [];

	public function load()
	{
		$this->loadWss();

		if ($this->app()->model()->module('mac.lan') === FALSE)
			return;

		$this->loadSCPlacements();
		$this->loadSensors();
		$this->createMainMenu();
	}

	function loadSCPlacements()
	{
		// -- placements
		$q = [];
		array_push ($q, 'SELECT placements.*');
		array_push ($q, ' FROM [mac_iot_scPlacements] AS [placements]');
		array_push ($q, ' WHERE 1');

		if ($this->app()->workplace)
		{
			array_push ($q, ' AND (', 'placementTo = %i', 0, 'AND placements.workplace = %i', $this->app()->workplace['ndx'], ')');
		}
		else
		{
			array_push ($q, ' AND (', 'placementTo = %i', 0, 'AND placements.workplace = %i', -1, ')');
		}

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$scp = ['mainMenu' => $r['mainMenu'], 'items' => []];

			$this->scPlacements[$r['ndx']] = $scp;
		}
	}

	function loadSensors()
	{
		if (!count($this->scPlacements))
			return;

		// -- sensors
		$q = [];
		$q[] = 'SELECT placements.ndx AS placementNdx, sensors.*';
		array_push ($q, ' FROM [mac_iot_scPlacements] AS placements');
		array_push ($q, ' LEFT JOIN [mac_iot_sensors] AS sensors ON placements.sensor = sensors.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND placements.ndx IN %in', array_keys($this->scPlacements));

		//array_push ($q, ' WHERE srcTableId = %s', 'mac.iot.scPlacements', 'AND dstTableId = %s', 'mac.iot.sensors');
		//array_push ($q, ' AND docLinks.linkId = %s', 'mac-sc-placements-sensors', 'AND srcRecId IN %in', array_keys($this->scPlacements));

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$scpNdx = $r['placementNdx'];
			$item = $r->toArray();
			$item['type'] = 'sensor';
			$item['mainMenu'] = $this->scPlacements[$scpNdx]['mainMenu'];

			$this->items[] = $item;
		}
	}

	function createMainMenu()
	{
		foreach ($this->items as $i)
		{
			if ($i['type'] === 'sensor')
			{
				$sh = new \mac\data\libs\SensorHelper($this->app());
				$sh->setSensor($i['ndx']);
				$si = $sh->getSensor(0, $i['mainMenu']);

				if (!isset($this->wss[$si['lan']]))
					continue;

				if ($i['mainMenu'] != 0)
					$this->mainMenu[] = $si;

				if (isset($si['lan']) && isset($si['topic']))
				{
					$this->wss[$si['lan']]['topics'][$si['topic']] = ['topic' => $si['topic'], 'sensorId' => $si['sensorNdx'], 'flags' => $si['flags']];
				}
			}
		}
	}

	function loadWss()
	{
		$wssAll = $this->app()->cfgItem ('mac.localServers', []);
		$this->wss = [];
		forEach ($wssAll as $ws)
		{
			//if ($ws['subsystems']['wss']['enabled'] !== 1)
			//	continue;
			if (!isset($ws['enableLC']) || !$ws['enableLC'])
				continue;
			//if (substr($ws['subsystems']['wss']['wsUrl'], 0, 7) === 'wss://:') // TODO: better solution needed
			//	continue;

			$enabled = FALSE;
			forEach ($ws['wssAllowedFrom'] as $af)
			{
				if ($af === substr ($_SERVER['REMOTE_ADDR'], 0, strlen($af)))
				{
					$enabled = TRUE;
					break;
				}
			}
			if ($enabled === FALSE)
				continue;
			$this->wss [$ws['lanNdx']] = [
				'id' => $ws['ndx'], 'name' => $ws['name'],
				'fqdn' => ($ws['mqttServerHost'] !== '') ? $ws['mqttServerHost'] : $ws['fqdn'],
				'port' => $ws['wsPort'],
				'wsUrl' => $ws['wsUrl'],
				//'postUrl' => $ws['subsystems']['wss']['postUrl'],
				'icon' => 'system/iconLocalServer',
				'topics' => [],
			];

			if ($this->app()->ngg)
			{
				$this->wss [$ws['lanNdx']]['wsUrl'] =  'wss://' . $this->wss [$ws['lanNdx']]['fqdn'] . ':' . $ws['wsPort'];
			}
		}
	}
}


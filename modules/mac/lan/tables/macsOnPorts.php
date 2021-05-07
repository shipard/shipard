<?php

namespace mac\lan;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableMacsOnPorts
 * @package mac\lan
 */
class TableMacsOnPorts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.macsOnPorts', 'mac_lan_macsOnPorts', 'MAC adresy na portech zařízení');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => '#'.$recData ['device']];

		return $h;
	}
}


/**
 * Class ViewMacsOnPorts
 * @package mac\lan
 */
class ViewMacsOnPorts extends TableView
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\lan\TableDevicesPorts */
	var $tableDevicesPorts;

	var $wallSockets = [];
	var $connectedTo = [];

	public function init ()
	{
		$this->tableDevices = $this->app()->table ('mac.lan.devices');
		$this->tableDevicesPorts = $this->app()->table ('mac.lan.devicesPorts');

		parent::init();
		//$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem['deviceNdx'] = $item['device'];
		$listItem['macs'] = json_decode($item['macs']);

		if ($item['deviceId'])
		{
			$dl = ['text' => $item['deviceId']];
			$listItem ['t1'] = $dl;
			$listItem ['icon'] = $this->tableDevices->tableIcon($item);
		}

		if ($item['portId'])
		{
			$pl = ['text' => $item['portId'], 'suffix' => '#'.$item['portNumber'], 'icon' => $this->tableDevicesPorts->tableIcon($item)];
			$listItem ['i1'] = $pl;
			//$listItem ['icon'] = $this->tableDevices->tableIcon($item);
		}
		else
		{
			$pl = ['text' => '#'.$item['portNumber'], 'icon' => 'icon-question-circle', 'class' => 'e10-off'];
			$listItem ['i1'] = $pl;
		}

		$listItem['t2'] = [];
		if ($item['connDeviceId'])
		{
			$cdl = ['class' => 'label label-default'];
			$cdl['text'] = $item['connDeviceId'];
			$cdl['icon'] = $this->tableDevices->tableIcon(['deviceKind' => $item['connDeviceKind']]);
			if ($item['connPortId'])
				$cdl['suffix'] = $item['connPortId'];

			if ($listItem['macs'] && count($listItem['macs']) === 1 && $listItem['macs'][0] === strtolower($item['connPortMac']))
				$cdl['class'] = 'label label-success';

			$listItem['t2'][] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => ''];
			$listItem['t2'][] = $cdl;
		}
		elseif ($item['connWallSocketId'])
		{
			$listItem ['ws'] = $item ['connWallSocketNdx'];
			$this->wallSockets[] = $item ['connWallSocketNdx'];

			$wsl = ['class' => 'label label-default'];
			$wsl['text'] = $item['connWallSocketId'];
			$wsl['icon'] = 'icon-square-o';

			$listItem['t2'][] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => ''];
			$listItem['t2'][] = $wsl;
		}

		if (!count($listItem['t2']))
			unset($listItem['t2']);


		$listItem['i2'] = [];
		if (!utils::dateIsBlank($item['updated']))
			$listItem['i2'][] = ['text' => utils::datef($item['updated'], '%S %T'), 'icon' => 'icon-clock-o', 'class' => ''];
//		if (!utils::dateIsBlank($item['created']))
//			$listItem['i2'][] = ['text' => utils::datef($item['created'], '%S %T'), 'icon' => 'icon-play-circle-o', 'class' => 'e10-small'];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		parent::decorateRow ($item);

		$pk = isset($item['ws']) ? $item['ws'] : -1;
		if (isset($this->connectedTo[$pk]))
		{
			$item['t3'] = [];
			foreach ($this->connectedTo[$pk] as $ci)
			{
				if ($ci['deviceNdx'] == $item['deviceNdx'])
					continue;

				if ($item['macs'] && count($item['macs']) === 1 && $item['macs'][0] === $ci['mac'])
					$ci['class'] = 'label label-success';

				$item['t3'][] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => 'pl1'];
				$item['t3'][] = $ci;
			}
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT [macsOnPorts].*, ";
		array_push ($q, ' devices.id AS deviceId, devices.deviceKind, devices.deviceType,');
		array_push ($q, ' devicesPorts.portId, devicesPorts.portKind, devicesPorts.portRole,');
		array_push ($q, ' connectedDevices.id AS connDeviceId, connectedDevices.deviceKind AS connDeviceKind, connectedDevices.deviceType AS connDeviceType,');
		array_push ($q, ' connectedPorts.portId AS connPortId, connectedPorts.mac AS connPortMac,');
		array_push ($q, ' connectedWallSockets.id AS connWallSocketId, connectedWallSockets.ndx AS connWallSocketNdx');
		array_push ($q, ' FROM [mac_lan_macsOnPorts] AS [macsOnPorts]');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON macsOnPorts.device = devices.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_devicesPorts AS devicesPorts ON ',
			'(macsOnPorts.device = devicesPorts.device',
			' AND macsOnPorts.portNumber = devicesPorts.portNumber',
			' AND devicesPorts.portKind < 10)');

		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS connectedDevices ON devicesPorts.connectedToDevice = connectedDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON devicesPorts.connectedToPort = connectedPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_wallSockets] AS connectedWallSockets ON devicesPorts.connectedToWallSocket = connectedWallSockets.ndx');

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, 'macsOnPorts.[macs] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR devices.[fullName] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR connectedDevices.[fullName] LIKE %s', '%' . $fts . '%');
			array_push($q, ')');
		}

		array_push ($q, ' ORDER BY [devices.id], [portNumber], [ndx]');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks) || !count($this->wallSockets))
			return;

		$devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');

		$q[] = 'SELECT ports.*, ';
		array_push($q, ' devices.id AS deviceId, devices.fullName AS deviceName, devices.deviceKind AS deviceKind');
		array_push($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND ports.connectedTo = %i', 1);
		array_push($q, ' AND ports.connectedToWallSocket IN %in', $this->wallSockets);

		array_push($q, ' ORDER BY devices.deviceKind DESC');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['deviceName'])
			{
				$dstItem = [
					/*'suffix' => $r['deviceId'], */'suffix' => $r['portId'], 'text' => $r['deviceName'],
					'icon' => $devicesKinds[$r['deviceKind']]['icon'], 'class' => '',
					'deviceNdx' => $r['device'], 'mac' => strtolower($r['mac']),
				];
				$this->connectedTo[$r['connectedToWallSocket']][] = $dstItem;
			}
		}
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class FormMacOnPorts
 * @package mac\lan
 */
class FormMacOnPorts extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->readOnly = TRUE;

		$this->openForm ();
			$this->addColumnInput ('device');
			$this->addColumnInput ('portNumber');
			$this->addColumnInput ('macs');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailMacOnPorts
 * @package mac\lan
 */
class ViewDetailMacOnPorts extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.dc.DocumentCardMacsOnPorts');
	}

	public function createToolbar ()
	{
		return [];
	}
}


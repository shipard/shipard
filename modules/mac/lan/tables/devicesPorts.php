<?php

namespace mac\lan;


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail;


/**
 * Class TableDevicesPorts
 * @package mac\lan
 */
class TableDevicesPorts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesPorts', 'mac_lan_devicesPorts', 'Porty síťových zařízení');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$portKind = $this->app()->cfgItem ('mac.lan.ports.kinds.'.$recData['portKind'], []);
		$portRole = $this->app()->cfgItem ('mac.lan.ports.roles.'.$recData['portRole'], []);
		if (!isset($portKind['useMac']))
			$recData['mac'] = '';
		if (!isset($portKind['useRole']))
			$recData['portRole'] = 0;

		if (!isset($portKind['useVlan']) && !isset($portRole['useVlan']))
			$recData['vlan'] = 0;

		if ($recData['mac'] !== '')
		{
			$this->db()->query ('UPDATE [mac_lan_devicesIfaces] SET [mac] = %s', $recData['mac'],
				' WHERE [device] = %i', $recData['device'], ' AND [devicePort] = %i', $recData['ndx']);
		}

		if ($recData['connectedTo'] == 0 || $recData['connectedTo'] == 3)
		{ // none or mobile
			$recData['connectedToWallSocket'] = 0;
			$recData['connectedToDevice'] = 0;
			$recData['connectedToPort'] = 0;
		}
		elseif ($recData['connectedTo'] == 1)
		{ // wallSocket
			$recData['connectedToDevice'] = 0;
			$recData['connectedToPort'] = 0;
		}
		elseif ($recData['connectedTo'] == 2)
		{ // device/port
			$recData['connectedToWallSocket'] = 0;
		}

		if ($recData['connectedToDevice'] == 0 || ($recData['OLD_connectedToDevice'] != $recData['connectedToDevice']))
			$recData['connectedToPort'] = 0;

		if ($recData['OLD_connectedToDevice'] != $recData['connectedToDevice'] || $recData['OLD_connectedToPort'] != $recData['connectedToPort'])
		{ // clear other side when connection was changed
			$this->db()->query ('UPDATE [mac_lan_devicesPorts] SET [connectedTo] = 0, ',
				'[connectedToDevice] = %i, ', 0, '[connectedToPort] = %i', 0,
				' WHERE [ndx] = %i', $recData['OLD_connectedToPort']);
		}

		if ($recData['connectedToPort'])
		{ // connect other side
			$this->db()->query ('UPDATE [mac_lan_devicesPorts] SET [connectedTo] = 2, ',
				'[connectedToDevice] = %i, ', $recData['device'], '[connectedToPort] = %i', $recData['ndx'],
				' WHERE [ndx] = %i', $recData['connectedToPort']);
		}

		unset($recData['OLD_connectedToWallSocket'], $recData['OLD_connectedToDevice'], $recData['OLD_connectedToPort']);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['portRole'] !== 0)
		{
			$portRole = $this->app()->cfgItem ('mac.lan.ports.roles.'.$recData['portRole'], NULL);
			if ($portRole)
				return $portRole['icon'];

			return 'system/iconWarning';
		}

		$portKind = $this->app()->cfgItem ('mac.lan.ports.kinds.'.$recData['portKind'], NULL);
		if ($portKind)
			return $portKind['icon'];

		return parent::tableIcon ($recData, $options);
	}

}

/**
 * Class FormDevicePort
 * @package mac\lan
 */
class FormDevicePort extends TableForm
{
	var $ownerRecData = NULL;
	/** @var \e10\DbTable */
	var $tableDevices = NULL;

	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		//$this->setFlag ('maximize', 1);

		$this->recData['OLD_connectedToWallSocket'] = $this->recData['connectedToWallSocket'];
		$this->recData['OLD_connectedToDevice'] = $this->recData['connectedToDevice'];
		$this->recData['OLD_connectedToPort'] = $this->recData['connectedToPort'];

		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->ownerRecData = $this->tableDevices->loadItem($this->recData['device']);//$this->option ('ownerRecData');
		$portKind = $this->app()->cfgItem ('mac.lan.ports.kinds.'.$this->recData['portKind'], []);
		$portRole = $this->app()->cfgItem ('mac.lan.ports.roles.'.$this->recData['portRole'], []);

		$this->openForm ();
			$this->openRow();
				$this->addColumnInput ('portKind');
				$this->addColumnInput ('portNumber');
				$this->addColumnInput ('portId');
			$this->closeRow();

			if (isset($portKind['useRole']))
				$this->addColumnInput ('portRole');

			if (isset($portRole['useVLANsList']) || isset($portKind['useBridgePorts']))
				$this->addList ('doclinks', '', TableForm::loAddToFormLayout);

			if (isset($portKind['useVlan']) || isset($portRole['useVlan']))
				$this->addColumnInput('vlan');

			if (isset($portKind['useMac']))
				$this->addColumnInput ('mac');

			$this->addSeparator(self::coH2);
			$this->addColumnInput ('connectedTo');
			if ($this->recData['connectedTo'] == 1)
				$this->addColumnInput ('connectedToWallSocket');
			elseif ($this->recData['connectedTo'] == 2)
			{
				$this->addColumnInput('connectedToDevice');
				$this->addColumnInput('connectedToPort');
			}

			$this->addSeparator(self::coH2);
			$this->addColumnInput ('note');

		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'mac.lan.devicesPorts' && $srcColumnId === 'connectedToPort')
		{
			$cp = [
				'connectedDevice' => $recData['connectedToDevice'],
				'portKind' => $recData['portKind']
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

	public function docLinkEnabled ($docLink)
	{
		$portKind = $this->app()->cfgItem ('mac.lan.ports.kinds.'.$this->recData['portKind'], []);
		$portRole = $this->app()->cfgItem ('mac.lan.ports.roles.'.$this->recData['portRole'], []);

		if ($docLink['linkid'] === 'mac-lan-devicePorts-vlans' && !isset($portRole['useVLANsList']))
			return FALSE;
		if ($docLink['linkid'] === 'mac-lan-devicePorts-bridgePorts' && !isset($portKind['useBridgePorts']))
			return FALSE;

		return parent::docLinkEnabled($docLink);
	}
}


/**
 * Class ViewDevicesPorts
 * @package mac\lan
 */
class ViewDevicesPorts extends TableView
{
	var $portsKinds;
	var $portsRoles;
	var $devicesKinds;

	public function init ()
	{
		if (!isset ($this->queryParams['connectedDevice']) && isset ($this->queryParams['comboRecData']) && isset ($this->queryParams['comboRecData']['device']))
			$this->queryParams['connectedDevice']= $this->queryParams['comboRecData']['device'];

		parent::init();

		$this->portsKinds = $this->app()->cfgItem ('mac.lan.ports.kinds');
		$this->portsRoles = $this->app()->cfgItem ('mac.lan.ports.roles');
		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$portKind = $this->portsKinds[$item['portKind']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['portId'];
		$listItem ['i1'] = '#'.$item['portNumber'];
		$listItem ['icon'] = $portKind['icon'];

		//$listItem ['t2'] = $portKind['name'];
		$portConnectTo = [];
		if ($item['connectedTo'] == 0)
		{ // not connected
			$portConnectTo[] = ['text' => '', 'icon' => 'icon-times', 'class' => ''];
		}
		elseif ($item['connectedTo'] == 1)
		{ // wallSocket
			if ($item['wallSocketId'])
			{
				$portConnectTo[] = [
					'text' => $item['wallSocketId'], 'icon' => 'icon-plug', 'class' => 'e10-bold'
				];
				if ($item['wallSocketPlaceName'])
					$portConnectTo[] = ['text' => $item['wallSocketPlaceName'], 'icon' => 'system/iconMapMarker', 'class' => 'e10-small'];
			}
			else
			{
				$portConnectTo[] = ['text' => '!!!', 'icon' => 'icon-square-o', 'class' => 'e10-bold'];
			}
		}
		elseif ($item['connectedTo'] == 2)
		{ // device/port
			if ($item['connectedDeviceId'])
			{
				$portConnectTo[] = [
					'suffix' => $item['connectedDeviceId'], 'text' => $item['connectedDeviceName'],
					'icon' => $this->devicesKinds[$item['connectedDeviceKind']]['icon'], 'class' => 'e10-bold',

				];

				if ($item['connectedPortNumber'])
				{
					$portConnectTo[] = [
						'text' => $item['connectedPortId'], 'suffix' => '#' . $item['connectedPortNumber'],
						'icon' => 'icon-arrow-circle-o-right', 'class' => ''];
				}
				else
					$portConnectTo[] = ['text' => '!!!', 'icon' => 'icon-arrow-circle-o-right', 'class' => 'e10-error'];
			}
		}
		elseif ($item['connectedTo'] == 3)
		{ // mobile
			$portConnectTo[] = ['text' => 'mobilní', 'icon' => 'icon-briefcase', 'class' => 'e10-small'];
		}

		$listItem['t2'] = $portConnectTo;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ports.*, devices.fullName as deviceName,';
		array_push ($q, ' wallSockets.id AS wallSocketId, wallSocketsPlaces.shortName AS wallSocketPlaceName,');
		array_push ($q, ' connectedDevices.id AS connectedDeviceId, connectedDevices.fullName AS connectedDeviceName, connectedDevices.deviceKind AS connectedDeviceKind,');
		array_push ($q, ' connectedPorts.portNumber AS connectedPortNumber, connectedPorts.portId AS connectedPortId');
		array_push ($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_wallSockets] AS wallSockets ON ports.connectedToWallSocket = wallSockets.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS wallSocketsPlaces ON wallSockets.place = wallSocketsPlaces.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS connectedDevices ON ports.connectedToDevice = connectedDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON ports.connectedToPort = connectedPorts.ndx');
		array_push ($q, ' WHERE 1');

		$connectedDevice = intval($this->queryParam('connectedDevice'));
		if ($connectedDevice)
			array_push ($q, ' AND ports.[device] = %i', $connectedDevice);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' ports.[fullName] LIKE %s', '%'.$fts.'%',
					' OR devices.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY ports.[rowOrder], ports.ndx ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class ViewDevicesPortsFormList
 * @package mac\lan
 */
class ViewDevicesPortsFormList extends \e10\TableViewGrid
{
	var $portsKinds;
	var $portsRoles;
	var $devicesKinds;
	var $device = 0;

	var $linkedPorts = [];
	var $linkedVlans = [];

	public function init ()
	{
		parent::init();

		$this->portsKinds = $this->app()->cfgItem ('mac.lan.ports.kinds');
		$this->portsRoles = $this->app()->cfgItem ('mac.lan.ports.roles');
		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->device = intval($this->queryParam('device'));
		$this->addAddParam('device', $this->device);

		$g = [
			'portInfo' => 'Port',
			'portRole' => 'Role',
			'portConnectTo' => 'Zapojeno do',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$portKind = $this->portsKinds[$item['portKind']];
		$portRole = $this->portsRoles[$item['portRole']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $portKind['icon'];

		$listItem ['portInfo'] = [
			['text' => $item['portId'], 'class' => 'e10-bold'],
			['text' => $portKind['sc'], 'class' => 'break e10-small']
		];

		// -- port role
		$listItem ['portRole'] =[];

		if (isset($portKind['useRole']))
		{
			$listItem ['portRole'][] = ['text' => $portRole['name'], 'class' => 'e10-bold'];
		}

		if (isset($portKind['useMac']) && $item['mac'] !== '')
			$listItem ['portRole'][] = ['text' => $item['mac'], 'icon' => 'icon-th', 'class' => 'break'];

		if ($item['vlanName'] && (isset($portRole['useVlan']) || isset($portKind['useVlan'])))
			$listItem ['portRole'][] = ['text' => $item['vlanName'], 'icon' => ($item['vlanIsGroup']?'icon-clone':'icon-square-o'), 'class' => 'break'];


		$portConnectTo = [];
		if ($item['connectedTo'] == 0)
		{ // not connected
			$portConnectTo[] = ['text' => '', 'icon' => 'icon-times', 'class' => ''];
		}
		elseif ($item['connectedTo'] == 1)
		{ // wallSocket
			if ($item['wallSocketId'])
			{
				$portConnectTo[] = [
					'text' => $item['wallSocketId'], 'icon' => 'icon-square-o', 'class' => 'e10-bold',
					'docAction' => 'edit', 'table' => 'mac.lan.wallSockets', 'pk' => $item['connectedToWallSocket']
					];
				if ($item['wallSocketPlaceName'])
					$portConnectTo[] = ['text' => $item['wallSocketPlaceName'], 'icon' => 'system/iconMapMarker', 'class' => 'break e10-small'];
			}
			else
			{
				$portConnectTo[] = ['text' => '!!!', 'icon' => 'icon-square-o', 'class' => 'e10-bold'];
			}
		}
		elseif ($item['connectedTo'] == 2)
		{ // device/port
			if ($item['connectedDeviceId'])
			{
				$portConnectTo[] = [
					'suffix' => $item['connectedDeviceId'], 'text' => $item['connectedDeviceName'],
					'icon' => $this->devicesKinds[$item['connectedDeviceKind']]['icon'], 'class' => 'e10-bold',
					'docAction' => 'edit', 'table' => 'mac.lan.devices', 'pk' => $item['connectedToDevice']
				];

				if ($item['connectedPortNumber'])
				{
					$portConnectTo[] = [
						'text' => $item['connectedPortId'], 'suffix' => '#' . $item['connectedPortNumber'],
						'icon' => 'icon-arrow-circle-o-right', 'class' => ''];
				}
				else
					$portConnectTo[] = ['text' => '!!!', 'icon' => 'icon-arrow-circle-o-right', 'class' => 'e10-error'];

				if ($item['connectedDeviceRackId'])
				{
					$portConnectTo[] = [
						'text' => $item['connectedDeviceRackName'], 'suffix' => $item['connectedDeviceRackId'],
						'icon' => 'icon-square', 'class' => 'break e10-small'];
				}
			}
		}
		elseif ($item['connectedTo'] == 3)
		{ // mobile
			$portConnectTo[] = ['text' => 'mobilní', 'icon' => 'icon-briefcase', 'class' => 'e10-small'];
		}

		$listItem ['note'] = $item['note'];

		$listItem ['portConnectTo'] = $portConnectTo;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPorts [$item ['pk']]))
		{
			$item ['portRole'] = array_merge($item ['portRole'], $this->linkedPorts[$item ['pk']]);
		}

		if (isset ($this->linkedVlans [$item ['pk']]))
		{
			$item ['portRole'][] = ['text' => '', 'class' => 'break'];
			$item ['portRole'] = array_merge($item ['portRole'], $this->linkedVlans[$item ['pk']]);
		}

	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ports.*,';
		array_push ($q, ' vlans.num AS vlanNum, vlans.fullName AS vlanName, vlans.isGroup AS vlanIsGroup,');
		array_push ($q, ' wallSockets.id AS wallSocketId, wallSocketsPlaces.shortName AS wallSocketPlaceName,');
		array_push ($q, ' connectedDevices.id AS connectedDeviceId, connectedDevices.fullName AS connectedDeviceName, connectedDevices.deviceKind AS connectedDeviceKind,');
		array_push ($q, ' connectedPorts.portNumber AS connectedPortNumber, connectedPorts.portId AS connectedPortId,');
		array_push ($q, ' connectedDevicesRacks.id AS connectedDeviceRackId, connectedDevicesRacks.fullName AS connectedDeviceRackName');
		array_push ($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_vlans] AS vlans ON ports.vlan = vlans.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_wallSockets] AS wallSockets ON ports.connectedToWallSocket = wallSockets.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS wallSocketsPlaces ON wallSockets.place = wallSocketsPlaces.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS connectedDevices ON ports.connectedToDevice = connectedDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON ports.connectedToPort = connectedPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS connectedDevicesRacks ON connectedDevices.rack = connectedDevicesRacks.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ports.[device] = %i', $this->device);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' ports.[fullName] LIKE %s', '%'.$fts.'%',
				' OR devices.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY ports.[rowOrder] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- bridges ports
		$q = [];
		array_push ($q, 'SELECT links.srcRecId, links.dstRecId,');
		array_push ($q, ' ports.portId AS portId');
		array_push ($q, ' FROM e10_base_doclinks AS links');
		array_push ($q, ' LEFT JOIN mac_lan_devicesPorts AS ports ON links.dstRecId = ports.ndx ');
		array_push ($q, ' WHERE srcTableId = %s', 'mac.lan.devicesPorts', ' AND dstTableId = %s', 'mac.lan.devicesPorts');
		array_push ($q, ' AND links.srcRecId IN %in', $this->pks);
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$l = ['text' => $r['portId'], 'class' => 'label label-default'];
			$this->linkedPorts[$r['srcRecId']][] = $l;
		}

		// -- vlans
		$q = [];
		array_push ($q, 'SELECT links.srcRecId, links.dstRecId,');
		array_push ($q, ' vlans.num AS vlanNum, vlans.id AS vlanId, vlans.isGroup AS vlanIsGroup');
		array_push ($q, ' FROM e10_base_doclinks AS links');
		array_push ($q, ' LEFT JOIN mac_lan_vlans AS vlans ON links.dstRecId = vlans.ndx ');
		array_push ($q, ' WHERE srcTableId = %s', 'mac.lan.devicesPorts', ' AND dstTableId = %s', 'mac.lan.vlans');
		array_push ($q, ' AND links.srcRecId IN %in', $this->pks);
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['vlanIsGroup'])
				$l = ['text' => strval($r['vlanId']), 'icon' => 'icon-clone', 'class' => 'label label-default'];
			else
				$l = ['text' => strval($r['vlanNum']), 'suffix' => $r['vlanId'], 'icon' => 'icon-square-o', 'class' => 'label label-default'];
			$this->linkedVlans[$r['srcRecId']][] = $l;
		}
	}

	public function createToolbar ()
	{
		$tlbr = parent::createToolbar();

		$tlbr[] = [
			'text' => 'Nastavit porty', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/actionSettings',
			'class' => 'mr1 pull-right',
			'data-class' => 'mac.lan.libs.LanDevicePortsWizard',
			'data-addparams' => '__lanDeviceNdx=' . $this->device,
		];

		return $tlbr;
	}
}


/**
 * Class ViewDevicesPortsFormListDetail
 * @package mac\lan
 */
class ViewDevicesPortsFormListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'port #'.$this->item['ndx']]]);
	}
}

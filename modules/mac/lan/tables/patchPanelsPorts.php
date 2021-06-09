<?php

namespace mac\lan;
use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail;


/**
 * Class TablePatchPanelsPorts
 * @package mac\lan
 */
class TablePatchPanelsPorts extends DbTable
{
	CONST ctNone = 0, ctWallSocket = 1, ctPatchPanel = 2, ctLanDevice = 3, ctOther = 9;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.patchPanelsPorts', 'mac_lan_patchPanelsPorts', 'Porty síťových zařízení');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		/*
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
		*/
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class FormPatchPanelPort
 * @package mac\lan
 */
class FormPatchPanelPort extends TableForm
{
	var $ownerRecData = NULL;
	/** @var \e10\DbTable */
	var $tablePatchPanels = NULL;

	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		//$this->setFlag ('maximize', 1);

		//$this->recData['OLD_connectedToWallSocket'] = $this->recData['connectedToWallSocket'];
		//$this->recData['OLD_connectedToDevice'] = $this->recData['connectedToDevice'];
		//$this->recData['OLD_connectedToPort'] = $this->recData['connectedToPort'];

		$this->tablePatchPanels = $this->app()->table('mac.lan.patchPanels');
		$this->ownerRecData = $this->tablePatchPanels->loadItem($this->recData['patchPanel']);//$this->option ('ownerRecData');
		//$portKind = $this->app()->cfgItem ('mac.lan.ports.kinds.'.$this->recData['portKind'], []);
		//$portRole = $this->app()->cfgItem ('mac.lan.ports.roles.'.$this->recData['portRole'], []);

		$this->openForm ();
			$this->openRow();
				$this->addColumnInput ('portNumber');
				$this->addColumnInput ('portId');
			$this->closeRow();


			$this->addSeparator(self::coH2);
			$this->addColumnInput ('cableTerm');

			if ($this->recData['cableTerm'] == TablePatchPanelsPorts::ctWallSocket)
				$this->addColumnInput ('cableTermWallSocket');
			elseif ($this->recData['cableTerm'] == TablePatchPanelsPorts::ctPatchPanel)
			{
				$this->addColumnInput('cableTermPatchPanel');
				$this->addColumnInput('cableTermPatchPanelPort');
			}
			elseif ($this->recData['cableTerm'] == TablePatchPanelsPorts::ctLanDevice)
			{
				$this->addColumnInput('cableTermDevice');
				$this->addColumnInput('cableTermDevicePort');
			}

			$this->addSeparator(self::coH2);
			$this->addColumnInput ('note');

		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'mac.lan.patchPanelsPorts' && $srcColumnId === 'cableTermPatchPanelPort')
		{
			$cp = [
				'cableTermPatchPanel' => $recData['cableTermPatchPanel'],
				'patchPanelKind' => $recData['patchPanelKind']
			];
			return $cp;
		}

		if ($srcTableId === 'mac.lan.patchPanelsPorts' && $srcColumnId === 'cableTermDevicePort')
		{
			$cp = ['connectedDevice' => $recData['cableTermDevice']];
			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * Class ViewPatchPanelsPorts
 * @package mac\lan
 */
class ViewPatchPanelsPorts extends TableView
{
	//var $portsKinds;
	//var $portsRoles;
	//var $devicesKinds;

	public function init ()
	{
		if (!isset ($this->queryParams['cableTermPatchPanel']) && isset ($this->queryParams['comboRecData']) && isset ($this->queryParams['comboRecData']['patchPanel']))
			$this->queryParams['cableTermPatchPanel']= $this->queryParams['comboRecData']['patchPanel'];

		parent::init();

		//$this->portsKinds = $this->app()->cfgItem ('mac.lan.ports.kinds');
		//$this->portsRoles = $this->app()->cfgItem ('mac.lan.ports.roles');
		//$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		//$portKind = $this->portsKinds[$item['portKind']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['portId'];
		$listItem ['i1'] = '#'.$item['portNumber'];
//		$listItem ['icon'] = $portKind['icon'];

	/*
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
*/
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ports.*';
		//array_push($q, ', devices.fullName as deviceName,');
		//array_push ($q, ' wallSockets.id AS wallSocketId, wallSocketsPlaces.shortName AS wallSocketPlaceName,');
		//array_push ($q, ' connectedDevices.id AS connectedDeviceId, connectedDevices.fullName AS connectedDeviceName, connectedDevices.deviceKind AS connectedDeviceKind,');
		//array_push ($q, ' connectedPorts.portNumber AS connectedPortNumber, connectedPorts.portId AS connectedPortId');
		array_push ($q, ' FROM [mac_lan_patchPanelsPorts] AS ports');
		//array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		//array_push ($q, ' LEFT JOIN [mac_lan_wallSockets] AS wallSockets ON ports.connectedToWallSocket = wallSockets.ndx');
		//array_push ($q, ' LEFT JOIN [e10_base_places] AS wallSocketsPlaces ON wallSockets.place = wallSocketsPlaces.ndx');
		//array_push ($q, ' LEFT JOIN [mac_lan_devices] AS connectedDevices ON ports.connectedToDevice = connectedDevices.ndx');
		//array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS connectedPorts ON ports.connectedToPort = connectedPorts.ndx');
		array_push ($q, ' WHERE 1');


		$cableTermPatchPanel = intval($this->queryParam('cableTermPatchPanel'));
		if ($cableTermPatchPanel)
			array_push ($q, ' AND ports.[patchPanel] = %i', $cableTermPatchPanel);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' ports.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY ports.[portNumber], ports.ndx ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class ViewPatchPanelsFormList
 * @package mac\lan
 */
class ViewPatchPanelsPortsFormList extends \e10\TableViewGrid
{
	var $patchPanel = 0;

	var $devicesKinds;
	var $cableTerms;

	public function init ()
	{
		parent::init();

		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
		$this->cableTerms = $this->app()->cfgItem('mac.lan.patchPanels.cableTerm');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->patchPanel = intval($this->queryParam('patchPanel'));
		$this->addAddParam('patchPanel', $this->patchPanel);

		$g = [
			'portInfo' => ' Port',
			'cableTerm' => 'Kabel zakončen v',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$ctCfg = $this->cableTerms[$item['cableTerm']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'icon-ellipsis-h';

		$listItem ['portInfo'] = [
			['text' => $item['portId'], 'class' => 'e10-bold'],
			//['text' => $portKind['sc'], 'class' => 'break e10-small']
		];

		// -- cable termination
		$listItem ['cableTerm'] =[];
		$listItem ['cableTerm'][] = ['text' => $ctCfg['fn'], 'class' => 'e10-off block'];

		if ($item['cableTerm'] == TablePatchPanelsPorts::ctNone)
		{
		}
		elseif ($item['cableTerm'] == TablePatchPanelsPorts::ctWallSocket)
		{
			$listItem ['cableTerm'][] = ['text' => $item['wallSocketId'], 'icon' => 'icon-square-o', 'class' => 'e10-bold'];
			if ($item['wallSocketPlaceName'])
				$listItem ['cableTerm'][] = ['text' => $item['wallSocketPlaceName'], 'icon' => 'system/iconMapMarker', 'class' => ''];
		}
		elseif ($item['cableTerm'] == TablePatchPanelsPorts::ctPatchPanel)
		{
			if ($item['cableTermPatchPanelId'])
			{
				$listItem['cableTerm'][] = [
					'suffix' => $item['cableTermPatchPanelId'], 'text' => $item['cableTermPatchPanelName'],
					'icon' => 'icon-ellipsis-h', 'class' => 'e10-bold'
				];

				if ($item['cableTermPatchPanelPortNumber'])
				{
					$listItem['cableTerm'][] = [
						'text' => $item['cableTermPatchPanelPortId'], 'suffix' => '#' . $item['cableTermPatchPanelPortNumber'],
						'icon' => 'icon-arrow-circle-o-right', 'class' => ''];
				}
				else
					$listItem['cableTerm'][] = ['text' => '!!!', 'icon' => 'icon-arrow-circle-o-right', 'class' => 'e10-error'];

			}
		}
		elseif ($item['cableTerm'] == TablePatchPanelsPorts::ctLanDevice)
		{
			if ($item['cableTermDeviceId'])
			{
				$listItem['cableTerm'][] = [
					'suffix' => $item['cableTermDeviceId'], 'text' => $item['cableTermDeviceName'],
					'icon' => $this->devicesKinds[$item['cableTermDeviceKind']]['icon'], 'class' => 'e10-bold'
				];

				if ($item['cableTermDevicePortNumber'])
				{
					$listItem['cableTerm'][] = [
						'text' => $item['cableTermDevicePortId']/*, 'suffix' => '#' . $r['connectedPortNumber']*/,
						'icon' => 'icon-arrow-circle-o-right', 'class' => ''];
				}
				else
					$listItem['cableTerm'][] = ['text' => '!!!', 'icon' => 'icon-arrow-circle-o-right', 'class' => 'e10-error'];
			}
		}


		$listItem ['note'] = $item['note'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ports.*,';
		//array_push ($q, ' vlans.num AS vlanNum, vlans.fullName AS vlanName, vlans.isGroup AS vlanIsGroup,');
		array_push ($q, ' wallSockets.id AS wallSocketId, wallSocketsPlaces.shortName AS wallSocketPlaceName,');
		array_push ($q, ' cableTermDevices.id AS cableTermDeviceId, cableTermDevices.fullName AS cableTermDeviceName, cableTermDevices.deviceKind AS cableTermDeviceKind,');
		array_push ($q, ' cableTermDevicesPorts.portNumber AS cableTermDevicePortNumber, cableTermDevicesPorts.portId AS cableTermDevicePortId,');
		array_push ($q, ' cableTermPatchPanels.id AS cableTermPatchPanelId, cableTermPatchPanels.fullName AS cableTermPatchPanelName, cableTermPatchPanels.patchPanelKind AS cableTermPatchPanelKind,');
		array_push ($q, ' cableTermPatchPanelsPorts.portNumber AS cableTermPatchPanelPortNumber, cableTermPatchPanelsPorts.portId AS cableTermPatchPanelPortId');

		//array_push ($q, ' connectedDevicesRacks.id AS connectedDeviceRackId, connectedDevicesRacks.fullName AS connectedDeviceRackName');
		array_push ($q, ' FROM [mac_lan_patchPanelsPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_wallSockets] AS wallSockets ON ports.cableTermWallSocket = wallSockets.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS wallSocketsPlaces ON wallSockets.place = wallSocketsPlaces.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS cableTermDevices ON ports.cableTermDevice = cableTermDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS cableTermDevicesPorts ON ports.cableTermDevicePort = cableTermDevicesPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_patchPanels] AS cableTermPatchPanels ON ports.cableTermPatchPanel = cableTermPatchPanels.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_patchPanelsPorts] AS cableTermPatchPanelsPorts ON ports.cableTermPatchPanelPort = cableTermPatchPanelsPorts.ndx');

		//array_push ($q, ' LEFT JOIN [mac_lan_patchPanels] AS cableTermPatchPanels ON ports.cableTermPatchPanel = cableTermPatchPanels.ndx');


		//array_push ($q, ' LEFT JOIN [mac_lan_racks] AS connectedDevicesRacks ON connectedDevices.rack = connectedDevicesRacks.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ports.[patchPanel] = %i', $this->patchPanel);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' ports.[fullName] LIKE %s', '%'.$fts.'%'
				//' OR devices.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY ports.[portNumber] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewPatchPanelPortFormListDetail
 * @package mac\lan
 */
class ViewPatchPanelPortFormListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'port #'.$this->item['ndx']]]);
	}
}

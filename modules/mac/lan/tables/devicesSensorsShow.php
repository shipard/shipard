<?php

namespace mac\lan;


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


/**
 * Class TableDevicesSensorsShow
 * @package mac\lan
 */
class TableDevicesSensorsShow extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesSensorsShow', 'mac_lan_devicesSensorsShow', 'Senzory zobrazované u zařízení');
	}
}


/**
 * Class FormDeviceSensorShow
 * @package mac\lan
 */
class FormDeviceSensorShow extends TableForm
{
	var $ownerRecData = NULL;
	/** @var \e10\DbTable */
	var $tableDevices = NULL;

	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		//$this->setFlag ('maximize', 1);

		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->ownerRecData = $this->tableDevices->loadItem($this->recData['device']);//$this->option ('ownerRecData');

		$this->openForm ();
			$this->addColumnInput ('sensor');
			$this->addColumnInput ('sensorLabel');

			if ($this->ownerRecData['deviceKind'] === 10)
			{
				$this->addSeparator(self::coH1);
				$this->addStatic('Umístění v obraze:', self::coH2);
				$this->addColumnInput('camPosH');
				$this->addColumnInput('camPosV');
			}
		$this->closeForm ();
	}
}


/**
 * Class ViewDevicesPorts
 * @package mac\lan
 */
class ViewDevicesSensorsShow extends TableView
{
	public function init ()
	{

		parent::init();

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
					$portConnectTo[] = ['text' => $item['wallSocketPlaceName'], 'icon' => 'icon-map-marker', 'class' => 'e10-small'];
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
 * Class ViewDevicesSensorsShowFormList
 * @package mac\lan
 */
class ViewDevicesSensorsShowFormList extends \e10\TableViewGrid
{
	var $device = 0;

	public function init ()
	{
		parent::init();


		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->device = intval($this->queryParam('device'));
		$this->addAddParam('device', $this->device);

		$g = [
			'sensorInfo' => 'Senzor',
			'preview' => 'Náhled',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		//$listItem ['icon'] = $portKind['icon'];

		$listItem ['sensorInfo'] = [
			['text' => $item['sensorFullName'], 'class' => 'e10-bold'],
		];

		$listItem ['portRole'] =[];

		$sh = new SensorHelper($this->app());
		$sh->setSensorInfo($item);
		$badgeCode = $sh->badgeCode();
		$listItem ['preview'] = $badgeCode;

		return $listItem;
	}


	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT sensorsToShow.*, ';
		array_push ($q, ' sensors.fullName AS sensorFullName, sensors.sensorBadgeLabel, sensors.sensorBadgeUnits');
		array_push ($q, ' FROM [mac_lan_devicesSensorsShow] AS sensorsToShow');
		array_push ($q, ' LEFT JOIN [mac_iot_sensors] AS sensors ON sensorsToShow.sensor = sensors.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND sensorsToShow.[device] = %i', $this->device);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' sensorsToShow.[sensorLabel] LIKE %s', '%'.$fts.'%',
				' OR sensors.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY sensorsToShow.[rowOrder] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDevicesSensorsShowListDetail
 * @package mac\lan
 */
class ViewDevicesSensorsShowListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'port #'.$this->item['ndx']]]);
	}
}

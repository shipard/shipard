<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \mac\data\libs\SensorHelper;
use \Shipard\Utils\Utils;


/**
 * Class TableSensors
 * @package mac\iot
 */
class TableSensors extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('mac.iot.sensors', 'mac_iot_sensors', 'Senzory');
	}

	public function checkBeforeSave(&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		if (!isset($recData['uid']) || $recData['uid'] === '')
			$recData['uid'] = Utils::createToken(20);

		if (isset($recData['ndx']) && $recData['ndx'])
		{
			$valueExist = $this->db()->query('SELECT ndx FROM [mac_iot_sensorsValues] WHERE ndx = %i', $recData['ndx'])->fetch();
			if (!$valueExist)
				$this->db()->query('INSERT INTO [mac_iot_sensorsValues] SET ndx = %i', $recData['ndx'], ', [time] = %d', Utils::now(), ', counter = 0, value = 0');
		}
	}

	public function createHeader($recData, $options)
	{
		$hdr = parent::createHeader($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['idName'] . ' #' . $recData['ndx']];

		return $hdr;
	}

	function copyDocumentRecord($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord($srcRecData, $ownerRecord);

		$recData['uid'] = '';

		return $recData;
	}

	public function tableIcon($recData, $options = NULL)
	{
		return $this->app()->cfgItem('mac.data.quantityTypes.' . $recData['quantityType'] . '.icon', 'x-cog');
	}

	public function columnInfoEnumSrc($columnId, $form)
	{
		return parent::columnInfoEnumSrc($columnId, $form);
	}

	public function sensorsCfg($srcLan)
	{
		$cfg = [];

		$q [] = 'SELECT sensors.*, ';
		array_push ($q, ' places.shortName AS placeShorName, places.id AS placeId,');
		array_push ($q, ' racks.fullName AS rackFullName, racks.id AS rackId,');
		array_push ($q, ' devices.fullName AS deviceFullName, devices.id AS deviceId, devices.deviceKind,');
		array_push ($q, ' zones.shortName AS zoneShortName, zones.fullPathId AS zoneId');
		array_push ($q, ' FROM [mac_iot_sensors] AS sensors');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON sensors.place = places.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS racks ON sensors.rack = racks.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON sensors.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_base_zones] AS zones ON sensors.zone = zones.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND sensors.docState = %i', 4000);
		array_push ($q, ' AND sensors.srcLan = %i', $srcLan);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['ndx' => $r['ndx'], 'id' => $r['idName']];
			$ndx = $r['srcMqttTopic'];

			if ($r['place'])
				$item['tags']['place'] = $r['placeId'];
			if ($r['zone'])
				$item['tags']['zone'] = str_replace('/', '_', substr($r['zoneId'], 1));
			if ($r['rack'])
				$item['tags']['rack'] = $r['rackId'];
			if ($r['device'])
				$item['tags']['device'] = $r['deviceId'];

			$cfg[$ndx] = $item;
		}

		return $cfg;
	}
}


/**
 * Class ViewSensors
 * @package mac\iot
 */
class ViewSensors extends TableView
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;

	var $devicesKinds;

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
		$this->tableDevices = $this->app()->table('mac.lan.devices');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => $item['idName'].' #'.$item['ndx'], 'class' => 'id'];

		if ($item['fullName'] !== '')
			$listItem ['t1'] = $item['fullName'];
		else
			$listItem ['t1'] = ['text' => $item['srcMqttTopic'], 'class' => 'e10-off'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t2'] = [];

		if ($item['sensorCounter'])
		{
			$sh = new SensorHelper($this->app());
			$sh->setSensor($item['ndx']);
			$badgeCode = $sh->badgeCode(1);
			if ($badgeCode !== '')
			{
				$listItem ['i2'] = ['code' => $badgeCode];
			}
		}
		if ($item['placeShorName'])
			$listItem ['t2'][] = ['text' => $item ['placeShorName'], 'suffix' => $item['placeId'], 'class' => 'label label-default', 'icon' => 'system/iconMapMarker'];
		if ($item['zoneShortName'])
			$listItem ['t2'][] = ['text' => $item ['zoneShortName'], 'suffix' => str_replace('/', '_', substr($item['zoneId'], 1)), 'class' => 'label label-default', 'icon' => 'icon-crosshairs'];
		if ($item['rackFullName'])
			$listItem ['t2'][] = ['text' => $item ['rackFullName'], 'suffix' => $item['rackId'], 'class' => 'label label-default', 'icon' => 'icon-window-maximize'];
		if ($item['deviceFullName'])
			$listItem ['t2'][] = ['text' => $item ['deviceFullName'], 'suffix' => $item['deviceId'], 'class' => 'label label-default', 'icon' => $this->tableDevices->tableIcon($item)];

		$props3 = [];
		$props3[] = ['text' => $item['srcMqttTopic']];

		if ($item['sensorTime'])
		{
			$props3[] = ['text' => Utils::datef($item['sensorTime'], '%S, %T'), 'class' => 'pull-right', 'icon' => 'system/iconClock'];
		}

		if (count($props3))
			$listItem ['t3'] = $props3;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT sensors.*, ';
		array_push ($q, ' sensorsValues.value AS sensorValue, sensorsValues.[time] AS sensorTime, sensorsValues.[counter] AS sensorCounter,');
		array_push ($q, ' places.shortName AS placeShorName, places.id AS placeId,');
		array_push ($q, ' racks.fullName AS rackFullName, racks.id AS rackId,');
		array_push ($q, ' devices.fullName AS deviceFullName, devices.id AS deviceId, devices.deviceKind,');
		array_push ($q, ' zones.shortName AS zoneShortName, zones.fullPathId AS zoneId');
		array_push ($q, ' FROM [mac_iot_sensors] AS sensors');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON sensors.place = places.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS racks ON sensors.rack = racks.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON sensors.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_base_zones] AS zones ON sensors.zone = zones.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_sensorsValues] AS sensorsValues ON sensors.ndx = sensorsValues.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' sensors.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR sensors.[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR sensors.[idName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR sensors.[srcMqttTopic] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'sensors.', ['[fullName]', '[srcMqttTopic]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSensor
 * @package mac\iot
 */
class FormSensor extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$quantityCfg =  $this->app()->cfgItem ('mac.data.quantityTypes.'.$this->recData['quantityType'], NULL);

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput('srcMqttTopic');
					$this->addSeparator(self::coH2);
					$this->addColumnInput('quantityType');
					$this->addColumnInput('fullName');
					$this->addColumnInput('shortName');
					$this->addColumnInput('idName');
					$this->addColumnInput('sensorBadgeLabel');
					$this->addColumnInput('sensorBadgeUnits');
					$this->addColumnInput('sensorIcon');

					if ($quantityCfg && isset($quantityCfg['login']) && $quantityCfg['login'])
						$this->addColumnInput('flagLogin');
					else
						$this->recData['flagLogin'] = 0;

					if ($quantityCfg && isset($quantityCfg['kbd']) && $quantityCfg['kbd'])
						$this->addColumnInput('flagKbd');
					else
						$this->recData['flagKbd'] = 0;

					$this->addSeparator(self::coH2);
					$this->addColumnInput('place');
					$this->addColumnInput('zone');
					$this->addColumnInput('rack');
					$this->addColumnInput('device');
					$this->addColumnInput('srcLan');
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput('saveToDb');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailSensor
 */
class ViewDetailSensor extends TableViewDetail
{
}

/**
 * class ViewDetailSensorValuesHistory
 */
class ViewDetailSensorValuesHistory extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('mac.iot.sensorsValuesHistory', 'default', ['sensorNdx' => $this->item ['ndx']]);
	}
}


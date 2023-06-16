<?php

namespace mac\lan;


use \Shipard\Viewer\TableView, \E10\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Viewer\TableViewPanel, \E10\DbTable, \E10\utils;
use \e10\base\libs\UtilsBase;

/**
 * Class TableDevices
 * @package mac\lan
 */
class TableDevices extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devices', 'mac_lan_devices', 'Zařízení v síti', 1163);
	}

	public function checkAfterSave2 (&$recData)
	{
		// -- set full port names
		$q = 'SELECT * FROM mac_lan_devicesPorts AS ports WHERE device = %i ORDER BY portNumber, portId';
		$ports = $this->db()->query ($q, $recData['ndx']);
		foreach ($ports as $port)
		{
			$fullName = $recData['fullName'].' / '.$port['portId'];
			$this->db()->query('UPDATE mac_lan_devicesPorts SET fullName = %s', $fullName, ' WHERE ndx = %i', $port['ndx']);
		}

		if ($recData['docState'] == 9800)
		{ // trash
			$this->db()->query('DELETE FROM [mac_lan_devicesCfgScripts] WHERE [device] = %i', $recData['ndx']);
			$this->db()->query('DELETE FROM [mac_lan_devicesCfgNodes] WHERE [device] = %i', $recData['ndx']);
		}

		if ($recData['docStateMain'] > 1)
		{
			if ($recData['deviceKind'] == 75)
			{
				$ibcu = new \mac\lan\libs\IotBoxCfgUpdater($this->app());
				$ibcu->init();
				$ibcu->updateOne($recData);
			}

			$une = new \mac\lan\libs\NodeServerCfgUpdater($this->app());
			$une->init();
			$une->update();
		}
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		if (isset($recData['deviceType']) && $recData['deviceType'])
		{
			$deviceType = $this->app()->loadItem($recData['deviceType'], 'mac.lan.deviceTypes');
			if ($deviceType)
				$recData['deviceKind'] = $deviceType['deviceKind'];
		}

		$recData['nodeSupport'] = 0;
		$recData['monitored'] = 0;
		$macDeviceCfg = json_decode($recData['macDeviceCfg'], TRUE);
		if ($macDeviceCfg)
		{
			if ($recData['deviceKind'] == 7)
			{
				if (
					(isset($macDeviceCfg['enableLC']) && $macDeviceCfg['enableLC'])	||
					(isset($macDeviceCfg['enableCams']) && $macDeviceCfg['enableCams'])	||
					(isset($macDeviceCfg['enableRack']) && $macDeviceCfg['enableRack'])	||
					(isset($macDeviceCfg['enableOthers']) && $macDeviceCfg['enableOthers'])
				)
				{
					$recData['nodeSupport'] = 1;
				}
			}

			if ((isset($macDeviceCfg['monNetdataEnabled']) && $macDeviceCfg['monNetdataEnabled']))
			{
				$recData['monitored'] = 1;
			}
		}

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
			$recData['id'] = strval ($recData['ndx']);

		if (isset($recData['uid']) && $recData['uid'] === '')
			$recData['uid'] = utils::createToken(32);
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'macDeviceType')
		{
			if (isset($cfgItem['dk']) && $form->recData['deviceKind'] != $cfgItem['dk'])
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function columnInfoEnumSrc ($columnId, $form)
	{
		if ($columnId === 'mdtFamily' || $columnId === 'mdtType')
		{
			if (!$form)
				return NULL;

			$recData = $form->recData;

			$deviceKind = $this->app()->cfgItem('mac.lan.devices.kinds.' . $recData['deviceKind'], NULL);
			if (!$deviceKind || !isset($deviceKind['isMacDevice']))
				return NULL;

			$macDeviceTypeId = $recData['macDeviceType'];
			$macDeviceTypeCfg = $this->app()->cfgItem('mac.devices.types.' . $macDeviceTypeId, NULL);
			if (!$macDeviceTypeCfg || !isset($macDeviceTypeCfg['cfg']))
				return FALSE;
			$cfgFileName = __SHPD_MODULES_DIR__.'mac/devices/devices/'.$macDeviceTypeCfg['cfg'].'.json';
			$cfg = utils::loadCfgFile($cfgFileName);
			if (!$cfg)
				return NULL;

			$enum = [];
			if ($columnId === 'mdtFamily')
			{
				if (!isset($cfg['families']))
					return NULL;
				foreach ($cfg['families'] as $familyId => $familyCfg)
					$enum[$familyId] = $familyCfg['title'];
			}
			elseif ($columnId === 'mdtType')
			{
				$familyCfg = $cfg['families'][$recData['mdtFamily']] ?? NULL;
				if (!$familyCfg || !isset($familyCfg['types']))
					return NULL;
				foreach ($familyCfg['types'] as $typeId => $typeCfg)
					$enum[$typeId] = $typeCfg['title'];
			}
			return $enum;
		}

		return parent::columnInfoEnumSrc ($columnId, $form);
	}

	public function createDeviceFromType ($data)
	{
		$newDevice = ['fullName' => $data['fullName'], 'deviceType' => $data['deviceType'], 'docState' => 1000, 'docStateMain' => 0];

		$tableLans = $this->app()->table('mac.lan.lans');
		$lanRecData = NULL;

		if (isset($data['lan']))
		{
			$newDevice['lan'] = $data['lan'];
			$lanRecData = $tableLans->loadItem($data['lan']);
		}
		if (isset($data['id']))
			$newDevice['id'] = $data['id'];
		if (isset($data['macDeviceType']))
			$newDevice['macDeviceType'] = $data['macDeviceType'];
		if (isset($data['rack']))
			$newDevice['rack'] = $data['rack'];
		if (isset($data['docState']))
			$newDevice['docState'] = $data['docState'];
		if (isset($data['docStateMain']))
			$newDevice['docStateMain'] = $data['docStateMain'];

		$pk = $this->dbInsertRec($newDevice);

		$q = 'SELECT * FROM mac_lan_deviceTypesPorts WHERE deviceType = %i ORDER BY ndx';
		$portTypes = $this->db()->query($q, $data['deviceType']);
		$devicePortNumber = 1;
		$devicePortNumberForId = 1;
		foreach ($portTypes as $pt)
		{
			$portsCount = intval($pt['portsCount']);
			for ($pn = 0; $pn < $portsCount; $pn++)
			{
				$portId = strval ($devicePortNumberForId);
				if ($pt['portIdMask'] !== '')
				{
					if (strpos($pt['portIdMask'], '%N') !== FALSE)
						$portId = str_replace('%N', $portId, $pt['portIdMask']);
					else
						$portId = $pt['portIdMask'].$portId;
				}

				$newPort = ['device' => $pk, 'portNumber' => $devicePortNumber, 'portId' => $portId, 'portKind' => $pt['portKind'], 'rowOrder' => $devicePortNumber * 100];

				if ($pt['portKind'] == 10 && $portsCount === 1 && $lanRecData)
					$newPort['vlan'] = $lanRecData['vlanManagement'];

				$this->db()->query('INSERT INTO [mac_lan_devicesPorts]', $newPort);

				$devicePortNumber++;
				$devicePortNumberForId++;
			}

			$devicePortNumberForId = 1;
		}

		$this->docsLog($pk);

		return $pk;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return $this->app()->cfgItem ('mac.lan.devices.kinds.'.$recData['deviceKind'].'.icon', 'tables/mac.lan.devices');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['deviceTypeName']];

		return $h;
	}

	public function loadAddresses ($pks, $class = '')
	{
		$addrTypes = $this->app()->cfgItem('mac.lan.ifacesAddrTypes');
		$list = [];

		$q = 'SELECT * FROM mac_lan_devicesIfaces AS ifaces WHERE device IN %in ORDER BY ndx';
		$rows = $this->db()->query ($q, $pks);
		foreach ($rows as $a)
		{
			$itm = ['icon' => 'icon-crosshairs', 'prefix' => $addrTypes[$a['addrType']]['sc'], 'id' => $a['id']];

			if ($class !== '')
				$itm['class'] = $class;

			if ($a['addrType'] === 2)
			{
				$itm['text'] = 'dhcp';
			}
			else
			{
				if ($a['ip'] !== '')
					$itm['text'] = $a['ip'];
				else
					$itm['text'] = '???';
			}
			if ($a['mac'] !== '')
				$itm['suffix'] = $a['mac'];

			if ($a['id'] !== '')
			{
				if (isset($itm['suffix']))
					$itm['suffix'] .= ' '.$a['id'];
				else
					$itm['suffix'] = $a['id'];
			}

			$list[$a['device']][] = $itm;
		}

		return $list;
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'macDeviceCfg')
		{
			$deviceKind = $this->app()->cfgItem('mac.lan.devices.kinds.' . $recData['deviceKind'], NULL);
			if (!$deviceKind || !isset($deviceKind['isMacDevice']))
				return FALSE;

			$macDeviceTypeId = $recData['macDeviceType'];
			$macDeviceTypeCfg = $this->app()->cfgItem('mac.devices.types.' . $macDeviceTypeId, NULL);
			if (!$macDeviceTypeCfg || !isset($macDeviceTypeCfg['cfg']))
				return FALSE;
			$cfgFileName = __SHPD_MODULES_DIR__.'mac/devices/devices/'.$macDeviceTypeCfg['cfg'].'.json';
			$cfg = utils::loadCfgFile($cfgFileName);
			if ($cfg && isset($cfg['fields']))
				return $cfg['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}

	public function iotBoxInterfaces ($recData)
	{
		$deviceKind = $this->app()->cfgItem('mac.lan.devices.kinds.' . $recData['deviceKind'], NULL);
		if (!$deviceKind || !isset($deviceKind['isMacDevice']))
			return [];

		$macDeviceTypeId = $recData['macDeviceType'];
		$macDeviceTypeCfg = $this->app()->cfgItem('mac.devices.types.' . $macDeviceTypeId, NULL);
		if (!$macDeviceTypeCfg || !isset($macDeviceTypeCfg['cfg']))
			return [];
		$cfgFileName = __SHPD_MODULES_DIR__.'mac/devices/devices/'.$macDeviceTypeCfg['cfg'].'.json';
		$cfg = utils::loadCfgFile($cfgFileName);
		if ($cfg && isset($cfg['iotBoxInterfaces']))
			return $cfg['iotBoxInterfaces'];

		return [];
	}


	public function deviceTitleLabel ($deviceRecData)
	{
		$title = [
			'text' =>  $deviceRecData['id'].($deviceRecData['id'] !== $deviceRecData['fullName'] ? ' ('.$deviceRecData['fullName'].')' : ''),
			'suffix' => '#'.$deviceRecData['ndx'],
			'icon' => $this->tableIcon($deviceRecData)
		];

		return $title;
	}

	function ioPortTypeCfg($portTypeId)
	{
		$portTypeCfg = $this->app()->cfgItem('mac.devices.io.ports.types.'.$portTypeId, NULL);
		if ($portTypeCfg && isset($portTypeCfg['cfgPath']))
		{
			$cfgFileName = __SHPD_MODULES_DIR__ . 'mac/devices/devices/iot/' . $portTypeCfg['cfgPath'] . '/cfg.json';
			$cfg = utils::loadCfgFile($cfgFileName);
			if ($cfg)
				return $cfg;
		}
		return NULL;
	}

	function gpioLayoutCore($gpioLayoutId)
	{
		$gpioLayoutFileName = __SHPD_MODULES_DIR__ . 'mac/devices/devices/iot/gpio/'.$gpioLayoutId.'.json';
		$gpioLayout = utils::loadCfgFile($gpioLayoutFileName);
		if ($gpioLayout)
			return $gpioLayout;

		return NULL;
	}

	function gpioLayoutFromRecData($deviceRecData)
	{
		$cfgFileName = __SHPD_MODULES_DIR__ . 'mac/devices/devices/iot/' . $deviceRecData['macDeviceType'] . '.json';
		$macDeviceSubTypeCfg = utils::loadCfgFile($cfgFileName);
		if ($macDeviceSubTypeCfg && isset($macDeviceSubTypeCfg['gpioLayout']))
		{
			$gpioCore = $this->gpioLayoutCore($macDeviceSubTypeCfg['gpioLayout']);
			$this->addGpioLayoutExtraPins($deviceRecData, $gpioCore);
			return $gpioCore;
		}

		return NULL;
	}

	function addGpioLayoutExtraPins($deviceRecData, &$gpioLayout)
	{
		$ioPortExpandersTypes = ['gpioExpanderI2C'];
		$ioPortExpandersDefs = $this->app()->cfgItem('mac.devices.i2cIOExpanders');

		$q = [];
		array_push($q,'SELECT * FROM [mac_lan_devicesIOPorts] WHERE device = %i', $deviceRecData['ndx']);
		array_push($q, ' AND [portType] IN %in', $ioPortExpandersTypes);
		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$portCfg = json_decode($r['portCfg'], TRUE);
			if (!$portCfg || !isset($portCfg['expType']) || !isset($portCfg['dir']))
				continue;

			$expDef = $ioPortExpandersDefs[$portCfg['expType']];

			// "ext1-8": {"title": "ext1.8 / GPIO3 / U0RXD / CONSOLE RX", "hwnr": 3, "flags": ["d", "in", "out", "hwInt", "disabled"]},

			foreach ($expDef['pins'] as $ep)
			{
				$pin = $ep;

				$dir = intval($portCfg['dir']);

				$pin['flags'][] = ($dir === 0) ? 'out' : 'in';
				$pin['title'] = /*$portCfg['i2cBusPortId'] . ' → ' .*/ $r['portId'] . ' → ' . $ep['id'];
				$pin['expPortId'] = $r['portId'];
				$pinId = $portCfg['i2cBusPortId'] . '.' . $r['portId'] . '.' . $ep['id'];

				$gpioLayout['pins'][$pinId] = $pin;
			}
		}
	}

	function macDeviceTypeCfg($macDeviceTypeId)
	{
		$macDeviceTypeCfg = $this->app()->cfgItem('mac.devices.types.' . $macDeviceTypeId, NULL);
		if (!$macDeviceTypeCfg || !isset($macDeviceTypeCfg['cfg']))
			return [];
		$cfgFileName = __SHPD_MODULES_DIR__.'mac/devices/devices/'.$macDeviceTypeCfg['cfg'].'.json';
		$cfg = utils::loadCfgFile($cfgFileName);

		return $cfg;
	}

	function sgClassId($deviceRecData)
	{
		$sgClassId = '';

		$macDeviceType = $this->app()->cfgItem('mac.devices.types.'.$deviceRecData['macDeviceType'], NULL);
		if ($macDeviceType && isset($macDeviceType['sgClassId']))
			$sgClassId = $macDeviceType['sgClassId'];

		$macDeviceTypeCfg = $this->macDeviceTypeCfg($deviceRecData['macDeviceType']);
		$mdtFamilyCfg = $macDeviceTypeCfg['families'][$deviceRecData['mdtFamily']] ?? [];
		if (isset($mdtFamilyCfg['sgClassId']))
			$sgClassId = $mdtFamilyCfg['sgClassId'];

		$mdtTypeCfg = $mdtFamilyCfg['types'][$deviceRecData['mdtType']] ?? [];
		if (isset($mdtTypeCfg['sgClassId']))
			$sgClassId = $mdtTypeCfg['sgClassId'];

		return $sgClassId;
	}
}


/**
 * Class ViewDevices
 * @package mac\lan
 */
class ViewDevices extends TableView
{
	var $addresses;
	var $classification;
	var $osInfo = [];
	var $deviceInfo = [];

	var $deviceKind = 0;

	var $usePropertyLink = FALSE;

	/** @var \mac\swlan\libs\SWDevicesUtils */
	var $swDeviceUtils;

	/** @var \mac\lan\libs\WatchdogsUtils */
	var $watchdogsUtils;

	var $devicesOSBadges = [];
	var $devicesWDBadges = [];

	public function init ()
	{
		parent::init();

		$this->swDeviceUtils = new \mac\swlan\libs\SWDevicesUtils($this->app());
		$this->watchdogsUtils = new \mac\lan\libs\WatchdogsUtils($this->app());

		if ($this->queryParam ('lan'))
			$this->addAddParam ('lan', $this->queryParam ('lan'));

		if ($this->app->model()->module('e10pro.property') !== FALSE)
			$this->usePropertyLink = TRUE;

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'off', 'title' => 'Sklad'];
		$mq [] = ['id' => 'archive', 'title' => 'Vyřazeno'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];

		$this->setMainQueries ($mq);

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['disableSNMP'] = $item ['disableSNMP'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		if ($item['id'] !== '')
			$listItem ['i1'] = ['text' => $item['id'], 'class' => 'id', 'suffix' => '#'.$item['ndx']];
		elseif ($item['evNumber'] !== '')
			$listItem ['i1'] = ['text' => $item['id'], 'class' => 'evNumber', 'suffix' => '#'.$item['ndx']];

		$props = [];

		if ($item['nodeSupport'])
			$props[] = ['icon' => 'system/iconCheck', 'text' => 'node', 'class' => 'label label-info'];
		if ($item['monitored'])
			$props[] = ['icon' => 'tables/mac.lan.lans', 'text' => 'Netdata', 'class' => 'label label-info'];

		if ($item['placeFullName'])
		{
			$placeLabel = ['icon' => 'system/iconMapMarker', 'text' => $item['placeFullName'], 'class' => ''];
			if ($item['placeDesc'] !== '')
				$placeLabel['suffix'] = $item['placeDesc'];
			$props[] = $placeLabel;
		}
		elseif ($item['placeDesc'] !== '')
			$props[] = ['icon' => 'system/iconMapMarker', 'text' => $item['placeDesc'], 'class' => ''];

		if (count($props))
			$listItem['t2'] = $props;

		$props = [];
		if ($item['rackName'])
			$props[] = ['text' => $item['rackName'], 'icon' => 'icon-window-maximize', 'class' => ''];

		if ($item['lanShortName'])
			$props[] = ['text' => $item['lanShortName'], 'icon' => 'system/iconSitemap', 'class' => ''];
		else
			$props[] = ['text' => '!!!', 'icon' => 'system/iconSitemap', 'class' => ''];

		if (count($props))
			$listItem['i2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->devicesOSBadges[$item ['pk']]))
		{
			if (!isset($item ['t3']))
				$item ['t3'] = [];
			$item ['t3'] = array_merge ($this->devicesOSBadges[$item ['pk']], $item ['t3']);
		}

		if (isset($this->devicesWDBadges[$item ['pk']]))
		{
			if (!isset($item ['t3']))
				$item ['t3'] = [];
			$item ['t3'][] = $this->devicesWDBadges[$item ['pk']]['first'];
		}

		if (isset ($this->classification [$item ['pk']]))
		{
			if (!isset($item ['t3']))
				$item ['t3'] = [];

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);

		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT devices.*, places.fullName as placeFullName, lans.shortName as lanShortName,';

		array_push ($q, ' [racks].fullName AS rackName');
		array_push ($q, ' FROM [mac_lan_devices] as devices');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_racks AS [racks] ON [devices].[rack] = [racks].ndx');

		if ($this->usePropertyLink)
			array_push ($q, ' LEFT JOIN e10pro_property_property AS property ON devices.property = property.ndx');

		array_push ($q, ' WHERE 1');

		// -- owner lan
		if ($this->queryParam ('lan'))
			array_push ($q, " AND [lan] = %i", $this->queryParam ('lan'));

		if ($this->deviceKind && is_array($this->deviceKind))
			array_push ($q, " AND [deviceKind] IN %in", $this->deviceKind);
		elseif ($this->deviceKind)
			array_push ($q, " AND [deviceKind] = %i", $this->deviceKind);

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
					'(devices.[fullName] LIKE %s', '%'.$fts.'%',
					' OR devices.[deviceTypeName] LIKE %s', '%'.$fts.'%',
					' OR devices.[id] LIKE %s', '%'.$fts.'%',
					' OR devices.[evNumber] LIKE %s', '%'.$fts.'%',
					')'
			);
			array_push ($q, ' OR EXISTS (SELECT ndx FROM mac_lan_devicesIfaces WHERE devices.ndx = device AND (ip LIKE %s OR mac LIKE %s))', '%'.$fts.'%', '%'.$fts.'%');
			array_push($q, ')');
		}

		$this->defaultQuery($q);

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE devices.ndx = recid AND tableId = %s', 'mac.lan.devices');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		if (isset ($qv['kinds']))
			array_push ($q, " AND devices.[deviceKind] IN %in", array_keys($qv['kinds']));

		if (isset ($qv['lans']))
			array_push ($q, " AND devices.[lan] IN %in", array_keys($qv['lans']));

		// -- applications
		if (isset($qv['apps']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM mac_lan_devicesProperties WHERE devices.ndx = device');
			array_push ($q, ' AND ([property] = %i', 3, ' AND [i2] IN %in', array_keys($qv['apps']), ' AND [deleted] = 0', ')');
			array_push ($q, ')');
		}

		// property types
		if (isset ($qv['propertyTypes']))
			array_push ($q, " AND property.[propertyType] IN %in", array_keys($qv['propertyTypes']));

		if ($this->usePropertyLink)
		{
			// others - without property
			$withoutProperty = isset ($qv['others']['withoutProperty']);
			if ($withoutProperty)
				array_push($q, ' AND devices.[property] = 0');
			// others - with property
			$withProperty = isset ($qv['others']['withProperty']);
			if ($withProperty)
				array_push($q, ' AND devices.[property] != 0');
		}

		// SNMP
		$withoutSNMP = isset ($qv['problems']['withoutSNMP']);
		$oldSNMP = isset ($qv['problems']['oldSNMP']);
		if ($withoutSNMP && $oldSNMP)
		{
			array_push ($q, ' AND (');
			array_push ($q, ' (devices.disableSNMP = 0 AND NOT EXISTS (SELECT ndx FROM mac_lan_devicesInfo WHERE devices.ndx = device))');
			$dateLimit = new \DateTime('2 weeks ago');
			array_push ($q, ' OR (devices.disableSNMP = 0 AND EXISTS (SELECT ndx FROM mac_lan_devicesInfo WHERE devices.ndx = device AND mac_lan_devicesInfo.dateUpdate < %d))', $dateLimit);
			array_push ($q, ')');
		}
		elseif ($withoutSNMP)
			array_push ($q, ' AND (devices.disableSNMP = 0 AND NOT EXISTS (SELECT ndx FROM mac_lan_devicesInfo WHERE devices.ndx = device))');
		elseif ($oldSNMP)
		{
			$dateLimit = new \DateTime('2 weeks ago');
			array_push ($q, ' AND (devices.disableSNMP = 0 AND EXISTS (SELECT ndx FROM mac_lan_devicesInfo WHERE devices.ndx = device AND mac_lan_devicesInfo.dateUpdate < %d))', $dateLimit);
		}

		if ($mainQuery == 'off' || $mainQuery == 'archive')
		{
			if ($mainQuery == 'off')
				array_push($q, ' AND devices.[docState] = 9100');
			else
				array_push($q, ' AND devices.[docState] = 9000');
			array_push($q, ' ORDER BY devices.[fullName], devices.[ndx]', $this->sqlLimit ());
		}
		else
			$this->queryMain ($q, 'devices.', ['devices.[fullName]', 'devices.[ndx]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->swDeviceUtils->devicesOSBadges($this->pks, $this->devicesOSBadges);
		$this->watchdogsUtils->devicesBadges($this->pks, $this->devicesWDBadges);

		$this->addresses = $this->table->loadAddresses ($this->pks);
		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks, 'label label-info pull-right');
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		// -- lans
		$lans = $this->db()->query ('SELECT ndx, fullName FROM mac_lan_lans WHERE docStateMain != 4')->fetchPairs ('ndx', 'fullName');
		$lans['0'] = 'Žádná síť';
		$this->qryPanelAddCheckBoxes($panel, $qry, $lans, 'lans', 'Sítě');

		// -- kinds
		$kinds = [];
		foreach ($this->app()->cfgItem('mac.lan.devices.kinds') as $ndx => $k)
			$kinds[$ndx] = $k['name'];
		$this->qryPanelAddCheckBoxes($panel, $qry, $kinds, 'kinds', 'Typy zařízení');

		// -- SNMP
		$chbxProblems = [
			'withoutSNMP' => ['title' => 'Chybějící SNMP informace', 'id' => 'withoutSNMP'],
			'oldSNMP' => ['title' => 'Zastaralé SNMP informace', 'id' => 'oldSNMP'],
			//'unlicensedSW' => ['title' => 'Chybějící SW licence', 'id' => 'unlicensedSW'],
		];
		$paramsProblems = new \E10\Params ($this->app());
		$paramsProblems->addParam ('checkboxes', 'query.problems', ['items' => $chbxProblems]);
		$qry[] = ['id' => 'problems', 'style' => 'params', 'title' => 'Problémy', 'params' => $paramsProblems];

		// -- others
		if ($this->usePropertyLink)
		{
			$chbxOthers = [
				'withoutProperty' => ['title' => 'Bez evidence majetku', 'id' => 'withoutProperty'],
				'withProperty' => ['title' => 'S evidencí majetku', 'id' => 'withProperty']
			];
			$paramsOthers = new \E10\Params ($this->app());
			$paramsOthers->addParam('checkboxes', 'query.others', ['items' => $chbxOthers]);
			$qry[] = ['id' => 'errors', 'style' => 'params', 'title' => 'Ostatní', 'params' => $paramsOthers];

			// -- property types
			$q [] = 'SELECT DISTINCT propertyTypes.ndx, propertyTypes.shortName ';
			array_push($q, ' FROM [mac_lan_devices] as devices');
			array_push($q, ' LEFT JOIN e10pro_property_property AS property ON devices.property = property.ndx');
			array_push($q, ' LEFT JOIN e10pro_property_types AS propertyTypes ON property.propertyType = propertyTypes.ndx');
			array_push($q, ' WHERE devices.property != 0 AND property.propertyType != 0');
			$propertyTypes = $this->db()->query($q)->fetchPairs('ndx', 'shortName');
			$this->qryPanelAddCheckBoxes($panel, $qry, $propertyTypes, 'propertyTypes', 'Typy majetku');
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createToolbar ()
	{
		$t = parent::createToolbar();
		unset ($t[0]);

		return $t;
	}
}


/**
 * Class ViewDevicesAll
 * @package mac\lan
 */
class ViewDevicesAll extends ViewDevices
{
	var $groupParam = NULL;
	var $devicesGroups;

	/** @var \mac\lan\TableLans */
	var $tableLans;

	public function init ()
	{
		$this->usePanelLeft = TRUE;
		$this->linesWidth = 40;

		$enum = [];
		$this->devicesGroups = $this->app()->cfgItem ('mac.lan.devices.groups');
		forEach ($this->devicesGroups as $dgNdx => $dg)
		{
			$enum[$dgNdx] = ['text' => $dg['name'], 'icon' => $dg['icon']];
		}

		$this->groupParam = new \E10\Params ($this->app);
		$this->groupParam->addParam('switch', 'devicesGroup', ['title' => '', 'switch' => $enum, 'list' => 1]);
		$this->groupParam->detectValues();

		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableLans->setViewerBottomTabs($this);

		parent::init();
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->groupParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->groupParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	function defaultQuery(&$q)
	{
		$groupNdx = intval($this->groupParam->detectValues()['devicesGroup']['value']);
		$devicesGroup = $this->devicesGroups[$groupNdx];

		$lan = intval($this->bottomTabId());
		if ($lan)
			array_push($q,' AND [devices].[lan] = %i', $lan);

		if (isset($devicesGroup['devicesKinds']))
			array_push ($q, ' AND devices.[deviceKind] IN %in', $devicesGroup['devicesKinds']);

		parent::defaultQuery($q);
	}
}


/**
 * Class ViewDevicesCameras
 * @package mac\lan
 */
class ViewDevicesCameras extends ViewDevices
{
	public function init ()
	{
		$this->deviceKind = 10;
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem = parent::renderRow($item);

		$image = UtilsBase::getAttachmentDefaultImage ($this->app(), $this->table->tableId(), $item['ndx']);
		if (isset($image ['smallImage']))
		{
			$listItem ['image'] = $image ['smallImage'];
			unset($listItem ['icon']);
		}

		return $listItem;
	}
}


/**
 * Class ViewDevicesIoT
 * @package mac\lan
 */
class ViewDevicesIoT extends ViewDevices
{
	public function init ()
	{
		$this->deviceKind = 75;
		parent::init();
	}
}

/**
 * Class ViewDevicesRouters
 * @package mac\lan
 */
class ViewDevicesRouters extends ViewDevices
{
	public function init ()
	{
		$this->deviceKind = 8;
		parent::init();
	}
}


/**
 * Class ViewDevicesWiFiAPs
 * @package mac\lan
 */
class ViewDevicesWiFiAPs extends ViewDevices
{
	public function init ()
	{
		$this->deviceKind = 15;
		parent::init();
	}
}


/**
 * Class ViewDevicesServers
 * @package mac\lan
 */
class ViewDevicesServers extends ViewDevices
{
	public function init ()
	{
		$this->deviceKind = [7, 70];
		parent::init();
	}
}

/**
 * Class ViewDevicesShipardNodes
 * @package mac\lan
 */
class ViewDevicesShipardNodes extends ViewDevices
{
	public function init ()
	{
		$this->deviceKind = [7, 70];
		parent::init();
	}
}

/**
 * Class FormDevice
 * @package mac\lan
 */
class FormDevice extends TableForm
{
	public function renderForm ()
	{
		//if ($this->renderFormReadOnly ())
		//	return;

		$isMacDevice = 0;
		$macDeviceCfg = NULL;
		$deviceKind = $this->app()->cfgItem ('mac.lan.devices.kinds.'.$this->recData['deviceKind'], NULL);
		if ($deviceKind && isset($deviceKind['isMacDevice']))
			$isMacDevice = 1;

		if ($isMacDevice && $this->recData['macDeviceType'] !== '')
		{
			$macDeviceCfg = $this->app()->cfgItem ('mac.devices.types.'.$this->recData['macDeviceType'], NULL);
		}

		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		if ($isMacDevice)
		{
			$tabs ['tabs'][] = ['text' => $deviceKind['name'], 'icon' => $deviceKind['icon']];
		}

		$tabs ['tabs'][] = ['text' => 'Adresy', 'icon' => 'formAddresses'];
		$tabs ['tabs'][] = ['text' => 'Porty', 'icon' => 'formPorts'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$tabs ['tabs'][] = ['text' => 'Senzory', 'icon' => 'formSensors'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
				$this->addColumnInput ('fullName');
				$this->addColumnInput ('id');
				$this->addSeparator(TableForm::coH3);

				$this->addColumnInput ('deviceType');
				if ($isMacDevice)
				{
					$this->openRow();
						$this->addColumnInput ('macDeviceType');
						if ($macDeviceCfg && isset($macDeviceCfg['useHWMode']))
							$this->addColumnInput ('hwMode', self::coNoLabel);

						if ($macDeviceCfg && isset($macDeviceCfg['useFamily']))
						{
							$this->addColumnInput ('mdtFamily', self::coNoLabel);
							$this->addColumnInput ('mdtType', self::coNoLabel);
						}
					$this->closeRow();
					if ($this->recData['hwMode'])
					{
						$this->addColumnInput ('hwServer');
						$this->addColumnInput ('vmId');
					}
				}

				$this->addSeparator(TableForm::coH3);
				$this->addColumnInput ('deviceTypeName');
				$this->addColumnInput ('place');
				$this->addColumnInput ('placeDesc');
				$this->addColumnInput ('rack');

				$this->addSeparator(TableForm::coH3);
				$this->addList ('clsf', '', TableForm::loAddToFormLayout);
			$this->closeTab ();

			if ($isMacDevice)
			{
				$this->openTab ();
					$this->addSubColumns('macDeviceCfg');
					//$this->addColumnInput ('localServer');
				$this->closeTab ();
			}

			$this->openTab (TableForm::ltNone);
				//$this->addList ('ifaces');
				$this->addListViewer ('ifaces', 'formList');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				//$this->addList ('ports');
				$this->addListViewer ('ports', 'formList');
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ('disableSNMP');
				$this->addColumnInput ('disableInstalledSW');
				$this->addColumnInput ('alerts');
				$this->addColumnInput ('hideFromDR');
				$this->addColumnInput ('uid');
				$this->addColumnInput ('evNumber');
				$this->addColumnInput ('property');
				$this->addColumnInput ('lan');
				if ($deviceKind && isset($deviceKind['useMonitoringDataSource']))
				{
					$this->addSeparator(TableForm::coH2);
					$this->addColumnInput('macDataSource');
				}

			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addListViewer ('sensorsShow', 'formList');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();


		$this->closeTabs ();
		$this->closeForm ();
	}

	public function renderFormReadOnly ()
	{
		if (!$this->readOnly)
			return FALSE;

		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('sidebarWidth', '0.33');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
		$this->addColumnInput ('fullName', TableForm::coHidden);
		$tabs ['tabs'][] = ['text' => 'Přehled', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Info', 'icon' => 'icon-info'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-attachments'];
		$this->openTabs ($tabs, TRUE);
			$this->openTab (TableForm::ltNone);
				$this->renderFormContent ();
			$this->closeTab ();
			$this->openTab (TableForm::ltNone);
				$this->renderFormContentInfo ();
			$this->closeTab ();
			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();
		$this->closeTabs ();
		$this->closeForm ();

		$this->renderSidebarInfo ();

		return TRUE;
	}

	public function renderFormContent ()
	{
		$this->content = [];

		$card = new \mac\lan\DocumentCardDevice($this->app());
		$card->setDocument($this->table, $this->recData);
		$card->createContent();

		$cr = new \E10\ContentRenderer ($this->app());
		$cr->setDocumentCard($card);

		$c = "<div style='font-size:100%; background-color: #f5f5f5;' class='e10-reportContent'>";
		$c .= $cr->createCode('body');
		$c .= '</div>';

		$this->appendElement($c);

	}

	public function renderFormContentInfo ()
	{
		$card = new \mac\lan\DocumentCardDeviceInfo($this->app());
		$card->setDocument($this->table, $this->recData);
		$card->createContent();

		$cr = new \E10\ContentRenderer ($this->app());
		$cr->setDocumentCard($card);

		$c = "<div style='font-size:100%; background-color: #f5f5f5;' class='e10-reportContent'>";
		$c .= $cr->createCode('body');
		$c .= '</div>';

		$this->appendElement($c);
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

		if ($srcTableId === 'mac.lan.devicesIfaces' && $srcColumnId === 'ipAddressSuffix')
		{
			$cp = [
				'addrRange' => $recData['range'],
				'thisSuffix' => $recData['ipAddressSuffix'],
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

	protected function renderSidebarInfo ()
	{
		$this->sidebar = '';
	}
}


/**
 * Class ViewDetailDeviceDetail
 * @package mac\lan
 */
class ViewDetailDeviceDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$card = new \mac\lan\DocumentCardDevice($this->app());
		$card->setDocument($this->table(), $this->item);
		$card->createContent();
		foreach ($card->content['body'] as $cp)
			$this->addContent($cp);
	}
}


/**
 * Class ViewDetailDeviceDetailInfo
 * @package mac\lan
 */
class ViewDetailDeviceDetailInfo extends TableViewDetail
{
	public function createDetailContent ()
	{
		$card = new \mac\lan\DocumentCardDeviceInfo($this->app());
		$card->setDocument($this->table(), $this->item);
		$card->createContent();
		if (!isset($card->content['body']))
			return;
		foreach ($card->content['body'] as $cp)
			$this->addContent($cp);
	}
}


/**
 * Class ViewDetailDeviceDetailSw
 * @package mac\lan
 */
class ViewDetailDeviceDetailSw extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.swlan.dc.DeviceSW');
	}
}


/**
 * Class ViewDetailDeviceDetailCfgScripts
 * @package mac\lan
 */
class ViewDetailDeviceDetailCfgScripts extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.dc.DocumentCardDeviceScripts');
	}
}



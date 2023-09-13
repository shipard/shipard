<?php

namespace mac\iot;

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


/**
 * Class TableDevices
 */
class TableDevices extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.devices', 'mac_iot_devices', 'IoT zařízení');
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData['docStateMain'] >= 0)
		{
			$update = [];
			if ($recData['deviceType'] === 'shipard')
			{
				$ibcu = new \mac\iot\libs\IotDeviceCfgUpdaterIotBox($this->app());
				$ibcu->init();
				$ibcu->update($recData, $update);
			}
			elseif ($recData['deviceType'] === 'zigbee')
			{
				$ibcu = new \mac\iot\libs\IotDeviceCfgUpdaterZigbee($this->app());
				$ibcu->init();
				$ibcu->update($recData, $update);
			}

			if (count($update))
			{
				$this->db()->query('UPDATE [mac_iot_devices] SET ', $update, ' WHERE [ndx] = %i', $recData['ndx']);
			}
		}

		parent::checkAfterSave2 ($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		//if (isset($recData['uid']) && $recData['uid'] === '')
		//	$recData['uid'] = utils::createToken(20);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$hdr ['info'][] = [
			'class' => 'info', 'value' =>
			[
				['text' => '#'.$recData['ndx'].'.'.$recData['friendlyId'], 'class' => 'pull-right'],
				['text' => $recData['deviceTopic'], 'class' => 'e10-small'],
			]
		];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		$icon = $this->app()->cfgItem ('mac.iot.devices.kinds.'.$recData['deviceKind'].'.icon', NULL);
		if ($icon)
			return $icon;

		return parent::tableIcon($recData, $options);
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'deviceVendor')
		{
			$cfgPath = 'mac.iot.devices.vendors.'.$form->recData['deviceType'];
			$cfgItem = $this->app()->cfgItem($cfgPath, NULL);
			if (!$cfgItem)
				return [];

			$enum = [];
			foreach ($cfgItem as $key => $value)
			{
				$enum[$key] = $value['fn'];
			}

			return $enum;
		}

		if ($columnId === 'deviceModel')
		{
			$fn = __SHPD_MODULES_DIR__.'/mac/iot/config/devices/'.$form->recData['deviceType'].'/'.$form->recData['deviceVendor'].'/_models.json';
			if (!is_readable($fn))
				return [];

			$cfgItem = json_decode(file_get_contents($fn), TRUE);
			if (!$cfgItem)
				return [];

			$enum = [];
			foreach ($cfgItem as $key => $value)
			{
				$enum[$key] = $value['fn'];
			}

			return $enum;
		}

		return parent::columnInfoEnum ($columnId, $valueType, $form);
	}

	function ioPortTypeCfg($portTypeId)
	{
		$cfgFileName = __SHPD_MODULES_DIR__ . 'mac/iot/config/ioPorts/' . $portTypeId . '.json';
		$cfg = utils::loadCfgFile($cfgFileName);
		if ($cfg)
			return $cfg;
		return NULL;
	}

	function iotDeviceCfg ($deviceTypeId, $vendorId, $modelId, $iotDeviceNdx = 0, $withExtraPins = FALSE)
	{
		$fn = __SHPD_MODULES_DIR__ . 'mac/iot/config/devices/'.$deviceTypeId.'/'.$vendorId.'/'.$modelId.'.json';

		$cfg = utils::loadCfgFile($fn);
		if (!$cfg)
			return NULL;

		if (isset($cfg['gpioLayout']))
		{
			$fngpl = __SHPD_MODULES_DIR__ . 'mac/iot/config/devices/'.$cfg['gpioLayout'].'.json';
			$gplCfg = utils::loadCfgFile($fngpl);
			$cfg['io']['pins'] = $gplCfg['io']['pins'];
		}

		if ($withExtraPins && $iotDeviceNdx)
			$this->addGpioLayoutExtraPins($iotDeviceNdx, $cfg['io']);

		return $cfg;
	}

	function iotDeviceCfgFromRecData($deviceRecData, $fullCfg = FALSE)
	{
		$deviceCfg = $this->iotDeviceCfg($deviceRecData['deviceType'], $deviceRecData['deviceVendor'], $deviceRecData['deviceModel'], $deviceRecData['ndx'], $fullCfg);
		return $deviceCfg;
	}

	function addGpioLayoutExtraPins($iotDeviceNdx, &$gpioLayout)
	{
		$ioPortExpandersTypes = ['gpio-expander/i2c', 'gpio-expander/rs485'];

		$q = [];
		array_push($q,'SELECT * FROM [mac_iot_devicesIOPorts] WHERE iotDevice = %i', $iotDeviceNdx);
		array_push($q, ' AND [portType] IN %in', $ioPortExpandersTypes);
		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$portCfg = json_decode($r['portCfg'], TRUE);

			if (!$portCfg || !isset($portCfg['expType'])/* || !isset($portCfg['dir'])*/)
				continue;
			$portTypeCfg = $this->ioPortTypeCfg($r['portType']);
			$expTypeColDef = Utils::searchArray($portTypeCfg['fields']['columns'], 'id', 'expType');
			if (!$expTypeColDef)
				continue;

			$ioPortExpandersDefs = $this->app()->cfgItem($expTypeColDef['enumCfg']['cfgItem']);
			$expDef = $ioPortExpandersDefs[$portCfg['expType']];

			foreach ($expDef['pins'] as $ep)
			{
				$pin = $ep;

				$dir = intval($portCfg['dir'] ?? 0);

				$pin['flags'][] = ($dir === 0) ? 'out' : 'in';
				$pin['title'] = /*$portCfg['i2cBusPortId'] . ' → ' .*/ $r['portId'] . ' → ' . $ep['id'];
				$pin['expPortId'] = $r['portId'];
				$pinId = $r['portId'] . '.' . $ep['id'];

				$gpioLayout['pins'][$pinId] = $pin;
			}
		}
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'deviceSettings')
		{
			$iotDeviceCfg = $this->iotDeviceCfgFromRecData($recData);
			if ($iotDeviceCfg && isset($iotDeviceCfg['fields']))
				return $iotDeviceCfg['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}

	public function upload ()
	{
		$uploadString = $this->app()->postData();
		$uploadData = json_decode($uploadString, TRUE);
		if ($uploadData === FALSE)
		{
			error_log ("mac.iot.devices::upload parse data error: ".json_encode($uploadString));
			return 'FALSE';
		}

		if (!$uploadData)
			return 'OK';

		$zia = new \mac\iot\libs\ZigbeeInfoAnalyzer($this->app());
		$zia->setData($uploadData);
		$res = $zia->run();

		return $res;
	}

	public function refreshDataModels()
	{
		$q [] = 'SELECT [iotDevices].*';
		array_push ($q, ' FROM [mac_iot_devices] AS [iotDevices]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] != %i', 9800);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$recData = $r->toArray();
			$this->checkAfterSave2($recData);
		}
	}
}


/**
 * Class ViewDevices
 */
class ViewDevices extends TableView
{
	//var ?\mac\iot\libs\IotDevicesUtils $iotDevicesUtils = NULL;

	public function init ()
	{
		parent::init();

		//$this->iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$icon = $this->table->tableIcon ($item);

		$deviceTypeCfg = $this->app()->cfgItem('mac.iot.devices.types.'.$item['deviceType'], NULL);
		$deviceKindCfg = $this->app()->cfgItem('mac.iot.devices.kinds.'.$item['deviceKind'], NULL);

		$listItem ['i1'] = ['text' => '#'.$item['ndx'] /*.'.'.substr($item['uid'], 0, 3).'...'.substr($item['uid'],-3)*/, 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $icon;

		$dkLabel = NULL;
		if ($deviceKindCfg)
		{
			$dkLabel = ['text' => $deviceKindCfg['fn'], 'class' => 'label label-default'];
			if ($deviceTypeCfg)
				$dkLabel['suffix'] = $deviceTypeCfg['sc'];
		}

		if ($dkLabel)
			$t2[] = $dkLabel;

		$t2[] = ['text' => $item['friendlyId'], 'class' => 'label label-default'];
		if ($item['friendlyId'] !== $item['hwId'])
			$t2[] = ['text' => $item['hwId'], 'class' => 'label label-default'];

		if ($item['placeName'])
			$t2[] = ['text' => $item['placeName'], 'class' => 'label label-default', 'icon' => 'tables/e10.base.places'];

		$listItem ['t2'] = $t2;

		$listItem ['t3'] = [];
		if ($item['uiName'] !== '')
			$listItem ['t3'][] = ['text' => $item['uiName'], 'class' => ''];

		$listItem ['t3'][] = ['text' => $item['deviceTopic'], 'class' => 'e10-off'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [iotDevices].*,';
		array_push ($q, ' places.fullName AS placeName');
		array_push ($q, ' FROM [mac_iot_devices] AS [iotDevices]');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON iotDevices.place = places.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [iotDevices].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [iotDevices].[uiName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [iotDevices].[friendlyId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [iotDevices].[hwId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'iotDevices.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormDevice
 * @package mac\iot
 */
class FormDevice  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$deviceTypeCfg = $this->app()->cfgItem('mac.iot.devices.types.'.$this->recData['deviceType']);
		$useIOPorts = $deviceTypeCfg['useIOPorts'] ?? 0;
		$connectiontypeCfg = NULL;
		if ($useIOPorts)
			$connectiontypeCfg = $this->app()->cfgItem('mac.io.connectionTypes.'.$this->recData['primaryConnectionType']);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		if ($useIOPorts)
			$tabs ['tabs'][] = ['text' => 'IO', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('deviceType');
					$this->addColumnInput ('deviceVendor');
					$this->addColumnInput ('deviceModel');

					$this->addSeparator(self::coH2);

					$this->addColumnInput ('fullName');
					$this->addColumnInput ('uiName');
					$this->addColumnInput ('friendlyId');
					$this->addColumnInput ('hwId');
					$this->addColumnInput ('place');
					$this->addColumnInput ('lan');
					$this->addColumnInput ('nodeServer');

					if ($useIOPorts)
					{
						$this->addSeparator(self::coH4);
						$this->addColumnInput ('primaryConnectionType');
						if ($connectiontypeCfg && $connectiontypeCfg['needOwner'])
						{
							$this->addColumnInput ('ownerIoTDevice');
							$this->addColumnInput ('ownerIoTPort');
						}
					}

					$this->addSeparator(self::coH4);
					$this->addSubColumns('deviceSettings');
				$this->closeTab ();

				if ($useIOPorts)
				{
					$this->openTab(TableForm::ltNone);
						$this->addListViewer('ioPorts', 'formList');
					$this->closeTab();
				}

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDevice
 */
class ViewDetailDevice extends TableViewDetail
{
	public function createDetailContent ()
	{
		if ($this->item['deviceType'] === 'shipard')
			$this->addDocumentCard('mac.iot.libs.dc.IoTDeviceIoTBox');
	}
}

/**
 * Class ViewDetailDeviceCfgScripts
 */
class ViewDetailDeviceCfgScripts extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.iot.dc.DCIotDeviceConfig');
	}
}



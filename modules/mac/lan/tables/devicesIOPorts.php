<?php

namespace mac\lan;


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, e10\utils, e10\uiutils;


/**
 * Class TableDevicesIOPorts
 * @package mac\lan
 */
class TableDevicesIOPorts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesIOPorts', 'mac_lan_devicesIOPorts', 'IO Porty Zařízení');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData['portId'] = str_replace('/', '-', $recData['portId']);

		$deviceId = $this->db()->query ('SELECT id FROM [mac_lan_devices] WHERE ndx = %i', $recData['device'])->fetch();
		if ($deviceId)
			$this->makeMqttTopic($recData, $deviceId['id']);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return parent::tableIcon ($recData, $options);
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'portCfg')
		{
			$portTypeCfg = $this->app()->cfgItem('mac.devices.io.ports.types.'.$recData['portType'], NULL);
			if ($portTypeCfg && isset($portTypeCfg['cfgPath']))
			{
				$cfgFileName = __SHPD_MODULES_DIR__ . 'mac/devices/devices/iot/' . $portTypeCfg['cfgPath'] . '/cfg.json';
				$cfg = utils::loadCfgFile($cfgFileName);
				if ($cfg)
					return $cfg['fields'];
			}
			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}

	public function subColumnEnum ($column, $form, $valueType = 'cfgText')
	{
		if (isset($column['enumCfgFlags']) && isset($column['enumCfgFlags']['type']) && $column['enumCfgFlags']['type'] === 'pin')
		{
			$enum = [];

			$device = $this->db()->query('SELECT * FROM [mac_lan_devices] WHERE ndx = %i', $form->recData['device'])->fetch();
			if ($device)
			{
				/** @var \mac\lan\TableDevices $tableDevices */
				$tableDevices = $this->app()->table('mac.lan.devices');
				$gpioLayout = $tableDevices->gpioLayoutFromRecData($device);

				if ($gpioLayout && isset($gpioLayout['pins']))
				{
					foreach ($gpioLayout['pins'] as $pinId => $pin)
					{
						if (isset($column['enumCfgFlags']['pinFlags']))
						{
							if (in_array('disabled', $pin['flags']))
								continue;
							$enabled = 1;
							foreach ($column['enumCfgFlags']['pinFlags'] as $pf)
							{
								if (!in_array($pf, $pin['flags']))
								{
									$enabled = 0;
									break;
								}
							}
							if (!$enabled)
								continue;
						}
						$enum[$pinId] = $pin['title'];
					}
				}
			}
			return $enum;
		}

		if (isset($column['enumCfgFlags']) && isset($column['enumCfgFlags']['type']) && $column['enumCfgFlags']['type'] === 'ioPortId')
		{
			$enum = [];
			$enum[''] = '---';

			$ioPorts = $this->db()->query('SELECT * FROM [mac_lan_devicesIOPorts] WHERE device = %i', $form->recData['device']);

			foreach ($ioPorts as $r)
			{
				if ($r['portId'] === '')
					continue;
				if ($r['ndx'] === $form->recData['device'])
					continue;

				$enum[$r['portId']] = $r['portId'];
			}

			return $enum;
		}

		return parent::subColumnEnum ($column, $form, $valueType);
	}

	public function mqttTopicBegin()
	{
		return 'shp/';
	}

	public function getMqttTopics($ioPortRecData)
	{
		$topics = ['labels' => []];

		$l = ['text' => $ioPortRecData['mqttTopic'], 'class' => 'label label-info'];
		$topics['labels'][] = $l;

		return $topics;
	}

	public function makeMqttTopic(&$ioPortRecData, $deviceId)
	{
		/** @var \mac\lan\TableDevices $tableDevices */
		$tableDevices = $this->app()->table('mac.lan.devices');

		$ioPortTypeCfg = $tableDevices->ioPortTypeCfg($ioPortRecData['portType']);
		$useValueKind = isset($ioPortTypeCfg['useValueKind']) ? intval($ioPortTypeCfg['useValueKind']) : 0;
		$valueStyleCfg = $this->app()->cfgItem('mac.iot.ioPortValueStyle.'.$ioPortRecData['valueStyle'], NULL);

		if (!$useValueKind && !isset($ioPortTypeCfg['fixedValuesTopic']))
		{ // IotBox port / control
			// "pattern": "iot-boxes/{{iotBoxId}}/{{ioPortId}}"
			$t = $this->mqttTopicBegin().'iot-boxes/'.$deviceId.'/'.$ioPortRecData['portId'];

			$ioPortRecData['mqttTopic'] = $t;
			return;
		}

		if (isset($ioPortTypeCfg['fixedValuesTopic']))
		{
			$topicPattern = $ioPortTypeCfg['fixedValuesTopic'];
		}
		else
		{
			$topicPattern = $valueStyleCfg['topicPattern'];
		}

		$replace = [
			'{{iotBoxId}}' => ($deviceId !== '') ? $deviceId : 'iot_box_'.$ioPortRecData['device'],
			'{{ioPortId}}' => isset($ioPortRecData['portId']) ? $ioPortRecData['portId'] : 'io_port_id_'.$ioPortRecData['device'].'_'.$ioPortRecData['ndx'],
			'{{valueClass}}' => isset($ioPortRecData['valueClass']) ? $ioPortRecData['valueClass'] : '',
		];

		$t = $this->mqttTopicBegin().strtr($topicPattern, $replace);
		$t = str_replace('//', '/', $t);
		$ioPortRecData['mqttTopic'] = $t;
	}
}


/**
 * Class FormDevicePort
 * @package mac\lan
 */
class FormDeviceIOPort extends TableForm
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
		$this->ownerRecData = $this->tableDevices->loadItem($this->recData['device']);

		$ioPortTypeCfg = $this->tableDevices->ioPortTypeCfg($this->recData['portType']);
		$useValueKind = isset($ioPortTypeCfg['useValueKind']) ? intval($ioPortTypeCfg['useValueKind']) : 0;

		$this->openForm ();
			$this->addColumnInput ('portType');
			$this->addSubColumns('portCfg');

			$this->addSeparator(self::coH2);
			if ($useValueKind)
			{
				$this->addColumnInput('valueStyle');
				//$this->addColumnInput('topicStyle');
				//$this->addColumnInput('mqttTopic');
				//$this->addColumnInput('valueKind');
			}

			$this->addColumnInput ('portId');
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('note');

			$this->addSeparator(self::coH2);
			$this->addColumnInput ('disabled');

			$this->addSeparator(self::coH2);
			$topics = $this->table->getMqttTopics($this->recData);
			$this->addStatic([
					'type' => 'line',
					'line' => $topics['labels'],
					'pane' => 'e10-pane-core e10-pane-table mr1 ml1 e10-bg-t8', 'paneTitle' => ['text' => 'MQTT:', 'class' => 'h3 block']
				]
			);
		$this->closeForm ();
	}
}


/**
 * Class ViewDevicesIOPorts
 * @package mac\lan
 */
class ViewDevicesIOPorts extends TableView
{
	var $portsKinds;
	var $devicesKinds;
	var $portTypes = [];

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		//$portKind = $this->portsKinds[$item['portKind']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['portId'];
		//$listItem ['icon'] = $portKind['icon'];

		if ($item['deviceFullName'])
			$listItem ['t2'][] = ['text' => $item['deviceFullName'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ports.*, devices.fullName as deviceFullName';
		array_push ($q, ' FROM [mac_lan_devicesIOPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push ($q, ' WHERE 1');

		if (count($this->portTypes))
			array_push ($q, ' AND [ports].[portType] IN %in', $this->portTypes);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' ports.[fullName] LIKE %s', '%'.$fts.'%',
				' OR ports.[portId] LIKE %s', '%'.$fts.'%',
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
 * Class ViewDevicesIOPortsSensors
 * @package mac\lan
 */
class ViewDevicesIOPortsSensors extends ViewDevicesIOPorts
{
	public function init()
	{
		$allPortTypes = $this->app()->cfgItem('mac.devices.io.ports.types', []);
		foreach ($allPortTypes as $ptId => $pt)
		{
			if (isset($pt['sensor']) && $pt['sensor'])
				$this->portTypes[] = $ptId;
		}

		parent::init();
	}
}

/**
 * Class ViewDevicesIOPortsCombo
 * @package mac\lan
 */
class ViewDevicesIOPortsCombo extends ViewDevicesIOPorts
{
	var $thingItemTypeNdx = 0;
	var $thingItemTypeCfg = NULL;
	var $enabledValuesKinds = NULL;

	public function init ()
	{
		if (isset ($this->queryParams['thingItemType']))
		{
			$this->thingItemTypeNdx = intval($this->queryParams['thingItemType']);
			if ($this->thingItemTypeNdx)
				$this->thingItemTypeCfg = $this->app()->cfgItem('mac.iot.things.itemsTypes.'.$this->thingItemTypeNdx, NULL);

			if ($this->thingItemTypeCfg && isset($this->thingItemTypeCfg['ioPortValuesTypes']))
				$this->enabledValuesKinds = $this->thingItemTypeCfg['ioPortValuesTypes'];
		}

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['portId'];
		$listItem ['i1'] = '';
		//$listItem ['icon'] = $portKind['icon'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ports.*, devices.fullName as deviceName';
		array_push ($q, ' FROM [mac_lan_devicesIOPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_valuesKinds] AS valuesKinds ON ports.valueKind = valuesKinds.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->enabledValuesKinds)
			array_push ($q, ' AND valuesKinds.valueType IN %in', $this->enabledValuesKinds);

		// -- fulltext
		/*
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' ports.[fullName] LIKE %s', '%'.$fts.'%',
				' OR devices.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}
		*/
		array_push ($q, ' ORDER BY ports.[rowOrder], ports.ndx ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDevicesIOPortsFormList
 * @package mac\lan
 */
class ViewDevicesIOPortsFormList extends \e10\TableViewGrid
{
	var $portsTypes;
	var $devicesKinds;
	var $device = 0;

	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	var $deviceRecData = NULL;
	var $macDeviceCfg = NULL;
	var $macDeviceTypeCfg = NULL;
	var $macDeviceSubTypeCfg = NULL;
	var $gpioLayout = NULL;
	var $usedHWPins = [];

	public function init ()
	{
		parent::init();

		$this->portsTypes = $this->app()->cfgItem ('mac.devices.io.ports.types');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->device = intval($this->queryParam('device'));
		$this->addAddParam('device', $this->device);

		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->deviceRecData = $this->tableDevices->loadItem($this->device);
		$this->macDeviceCfg = json_decode($this->deviceRecData['macDeviceCfg'], TRUE);

		$this->macDeviceTypeCfg = $this->app()->cfgItem('mac.devices.types.' . $this->deviceRecData['macDeviceType'], NULL);
		$cfgFileName = __SHPD_MODULES_DIR__ . 'mac/devices/devices/' . $this->macDeviceTypeCfg['cfg'] . '.json';
		$this->macDeviceSubTypeCfg = utils::loadCfgFile($cfgFileName);

		$this->gpioLayout = $this->tableDevices->gpioLayoutFromRecData(/*$this->macDeviceSubTypeCfg['gpioLayout']*/$this->deviceRecData);

		$g = [
			'portId' => 'Port',
			'pins' => 'Piny',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$portType = $this->portsTypes[$item['portType']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'system/iconCogs';

		$listItem ['portId'] = [];

		if ($item['disabled'])
			$listItem ['portId'][] = ['text' => 'Zakázáno', 'class' => 'label label-danger'];

		$listItem ['portId'][] = ['text' => $item['portId'], 'class' => 'break e10-bold'];
		$listItem ['portId'][] = ['text' => $item['fullName'], 'class' => 'break e10-small'];
		$listItem ['portId'][] = ['text' => $portType['name'], 'class' => 'break e10-small'];

		$listItem ['note'] = [];
		if ($item['note'] !== '')
			$listItem ['note'][] = ['text' => $item['note'], 'class' => 'block'];

		// -- pins
		$pinsLabels = [];
		$settingsProps = [];
		$portCfg = json_decode($item['portCfg'], TRUE);
		foreach ($portCfg as $key => $value)
		{
			$ioPortTypeCfg = $this->tableDevices->ioPortTypeCfg($item['portType']);

			$portTypeCfgColumn = utils::searchArray($ioPortTypeCfg['fields']['columns'], 'id', $key);

			$columnEnabled = uiutils::subColumnEnabled ($portTypeCfgColumn, $portCfg);
			if ($columnEnabled === FALSE)
				continue;

			if ($portTypeCfgColumn && isset($portTypeCfgColumn['enumCfgFlags']['type']) && $portTypeCfgColumn['enumCfgFlags']['type'] === 'pin')
			{
				$pinsLabels[] = [
					'text' => $portTypeCfgColumn['name'].': ', 'class' => 'width20 block pull-left number pr1'];

				$pinCfg = isset($this->gpioLayout['pins'][$value]) ? $this->gpioLayout['pins'][$value] : NULL;
				if ($pinCfg)
				{
					$hwPin = isset($pinCfg['expPortId']) ? $pinCfg['expPortId'].':'.$pinCfg['hwnr']: strval($pinCfg['hwnr']);
					if (!isset($this->usedHWPins[$hwPin]))
						$this->usedHWPins[$hwPin] = 1;
					else
						$this->usedHWPins[$hwPin]++;

					$pinsLabels[] = ['text' => $pinCfg['title'], 'class' => 'label label-default'];

					if ($this->usedHWPins[$hwPin] === 1)
						$pinsLabels[] = ['text' => '#'.$hwPin, 'class' => 'label label-info'];
					else
						$pinsLabels[] = ['text' => '#'.$hwPin, 'suffix' => 'vícenásobné použití', 'icon' => 'system/iconWarning', 'class' => 'label label-danger'];

					$pinsLabels[] = ['text' => '', 'class' => 'break'];
				}
				else
				{
					$pinsLabels[] = ['text' => 'Chyba v konfiguraci pinu', 'icon' => 'system/iconWarning', 'class' => 'label label-danger'];
				}
			}
			else
			{
				$settingsProps[] = ['text' => $portTypeCfgColumn['name'].':', 'class' => ''];

				$showValue = $value;
				if (isset($portTypeCfgColumn['enumCfg']['cfgItem']))
				{
					$cfgItem = $this->app()->cfgItem($portTypeCfgColumn['enumCfg']['cfgItem'].'.'.$value, NULL);
					if ($cfgItem && isset($cfgItem[$portTypeCfgColumn['enumCfg']['cfgText']]))
						$showValue = $cfgItem[$portTypeCfgColumn['enumCfg']['cfgText']];
				}

				$settingsProps[] = ['text' => strval($showValue), 'class' => ''];
				$settingsProps[] = ['text' => '', 'class' => 'break'];
			}
		}

		if (count($pinsLabels))
			$listItem['pins'] = $pinsLabels;

		if (count($settingsProps))
			$listItem['note'] = array_merge ($listItem['note'], $settingsProps);

		$topics = $this->table->getMqttTopics($item);
		$listItem['note'] = array_merge ($listItem['note'], $topics['labels']);

		return $listItem;
	}

	function decorateRow (&$item)
	{
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ports.*';
		array_push ($q, ' FROM [mac_lan_devicesIOPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
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
	}
}


/**
 * Class ViewDevicesIOPortsFormListDetail
 * @package mac\lan
 */
class ViewDevicesIOPortsFormListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'port #'.$this->item['ndx']]]);
	}
}

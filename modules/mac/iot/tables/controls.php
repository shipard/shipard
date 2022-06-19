<?php

namespace mac\iot;

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


/**
 * Class TableControls
 * @package mac\iot
 */
class TableControls extends DbTable
{
	CONST
		cttIoTThingAction = 0,
		cttIotBoxIOPort = 4,
		cttMQTTTopic = 12;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.controls', 'mac_iot_controls', 'Ovládací prvky');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		if (isset($recData['uid']) && $recData['uid'] === '')
			$recData['uid'] = utils::createToken(20);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => '#'.$recData['ndx'].'.'.$recData['uid']];

		return $hdr;
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData['uid'] = '';

		return $recData;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		//return $this->app()->cfgItem ('mac.control.controlsKinds.'.$recData['controlKind'].'.icon', 'x-cog');

		return parent::tableIcon($recData, $options);
	}

	/*
	public function columnInfoEnumSrc ($columnId, $form)
	{
		if ($columnId === 'dstIoTThingAction')
		{
			if (!$form || !isset($form->recData['dstIoTThing']) || !$form->recData['dstIoTThing'])
				return NULL;

			$enum = ['' => '---'];

			$thing = $this->db()->query('SELECT * FROM [mac_iot_things] WHERE ndx = %i', $form->recData['dstIoTThing'])->fetch();
			if ($thing)
			{
				$thingKindCfg = $this->app()->cfgItem('mac.iot.things.kinds.'.$thing['thingKind'], NULL);

				if ($thingKindCfg)
				{
					$thingTypeCfg = $this->app()->cfgItem('mac.iot.things.types.'.$thingKindCfg['thingType'], NULL);
					if ($thingTypeCfg && isset($thingTypeCfg['actions']))
					{
						foreach ($thingTypeCfg['actions'] as $actionId => $action)
						{
							$enum[$actionId] = $action['fn'];
						}
					}
				}
			}

			return $enum;
		}

		return parent::columnInfoEnumSrc ($columnId, $form);
	}
	*/

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		$iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());

		if ($columnId === 'iotDeviceProperty')
		{
			if ($form->recData['useGroup'])
				$events = $iotDevicesUtils->devicesGroupProperties($form->recData['iotDevicesGroup']);
			else
				$events = $iotDevicesUtils->deviceProperties($form->recData['iotDevice']);
			if (!$events)
				return [];

			$enum = [];
			foreach ($events as $key => $value)
				$enum[$key] = $key;

			return $enum;
		}

		if ($columnId === 'iotDevicePropertyValueEnum')
		{
			if ($form->recData['useGroup'])
				$events = $iotDevicesUtils->devicesGroupProperties($form->recData['iotDevicesGroup']);
			else
				$events = $iotDevicesUtils->deviceProperties($form->recData['iotDevice']);

			if (!$events)
				return [];

			$event = $events[$form->recData['iotDeviceProperty']] ?? NULL;	
			if (!$event)
				return [];

			$enum = [];
			if (isset($event['enumSet']))
			{
				foreach ($event['enumSet'] as $key => $value)
					$enum[$key] = $key;
			}
			elseif (isset($event['enum']))
			{
				foreach ($event['enum'] as $key => $value)
					$enum[$key] = $key;
			}

			return $enum;
		}

		if ($columnId === 'iotSetupRequest')
		{
			$enum = [];

			$requests = $iotDevicesUtils->iotSetupRequests($form->recData['iotSetup']);
			foreach ($requests as $requestId => $req)
			{
				$enum[$requestId] = $req['fn'];
			}

			return $enum;
		}

		return parent::columnInfoEnum ($columnId, $valueType, $form);
	}

}


/**
 * Class ViewControls
 * @package mac\iot
 */
class ViewControls extends TableView
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;

	/** @var \mac\iot\TableThings */
	var $tableThings;

	var $devicesKinds;

	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableThings = $this->app()->table('mac.iot.things');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'].'.'.substr($item['uid'], 0, 3).'...'.substr($item['uid'],-3), 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		/*
		if ($item['targetType'] == TableControls::cttIotBoxIOPort)
		{
			if ($item['ioPortDeviceFullName'])
				$listItem ['t2'][] = ['text' => $item ['ioPortDeviceFullName'], 'class' => 'label label-default', 'icon' => $this->tableDevices->tableIcon(['deviceKind' => $item['ioPortDeviceKind']])];

			$ioPortId = ['text' => '', 'class' => 'label label-default'];
			if ($item['ioPortId'])
				$ioPortId['text'] = $item['ioPortId'];
			if ($item['ioPortFullName'])
				$ioPortId['suffix'] = $item['ioPortFullName'];

			$listItem ['t2'][] = $ioPortId;

		}
		elseif ($item['targetType'] == TableControls::cttMQTTTopic)
		{
		}
*/
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [controls].* ';
		//array_push ($q, ' ioPortsDevices.fullName AS [ioPortDeviceFullName], ioPortsDevices.deviceKind AS [ioPortDeviceKind],');
		//array_push ($q, ' ioPorts.portId AS [ioPortId], ioPorts.fullName AS [ioPortFullName]');
		array_push ($q, ' FROM [mac_iot_controls] AS [controls]');
		//array_push ($q, ' LEFT JOIN [mac_lan_devicesIOPorts] AS ioPorts ON [controls].dstIOPort = ioPorts.ndx');
		//array_push ($q, ' LEFT JOIN [mac_lan_devices] AS ioPortsDevices ON ioPorts.device = ioPortsDevices.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [controls].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [controls].[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'controls.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormControl
 * @package mac\iot
 */
class FormControl  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());


		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('controlType');

					if ($this->recData['controlType'] === 'setDeviceProperty')
					{
						if ($this->recData['useGroup'])
							$this->addColumnInput ('iotDevicesGroup');
						else
							$this->addColumnInput ('iotDevice');

						$this->addColumnInput ('iotDeviceProperty');

						if ($this->recData['useGroup'])
							$properties = $iotDevicesUtils->devicesGroupProperties($this->recData['iotDevicesGroup']);
						else
							$properties = $iotDevicesUtils->deviceProperties($this->recData['iotDevice']);

						$dp = $properties[$this->recData['iotDeviceProperty']] ?? NULL;
						if ($dp)
						{
							if ($dp['data-type'] === 'binary' || $dp['data-type'] === 'enum')
								$this->addColumnInput ('iotDevicePropertyValueEnum');
							else
								$this->addColumnInput ('iotDevicePropertyValue');

							$this->addSubColumns('eventValueCfg');
						}
					}
					elseif ($this->recData['controlType'] === 'sendSetupRequest')
					{
						$this->addColumnInput ('iotSetup');
						$this->addColumnInput ('iotSetupRequest');
					}
					elseif ($this->recData['controlType'] === 'sendMqttMsg')
					{
						$this->addColumnInput ('mqttTopic');
						$this->addColumnInput ('mqttTopicPayloadValue');
					}


					$this->addSeparator(self::coH2);

					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('idName');
					$this->addColumnInput ('lan');
					if ($this->recData['controlType'] !== 'sendSetupRequest')
						$this->addColumnInput ('iotSetup');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailControl
 * @package mac\iot
 */
class ViewDetailControl extends TableViewDetail
{
}


<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


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
		elseif ($item['targetType'] == TableControls::cttIoTThingAction)
		{
			if ($item['thingName'])
				$listItem ['t2'][] = ['text' => $item ['thingName'], 'class' => 'label label-default', 'icon' => $this->tableThings->tableIcon([/*'icon' => $item['thingIcon'],*/ 'thingKind' => $item['thingKind']])];

			$actionLabel = ['text' => '', 'class' => 'label label-default'];
			$thingKindCfg = $this->app()->cfgItem('mac.iot.things.kinds.'.$item['thingKind'], NULL);
			if ($thingKindCfg)
			{
				$thingTypeCfg = $this->app()->cfgItem('mac.iot.things.types.'.$thingKindCfg['thingType'], NULL);
				if ($thingTypeCfg && isset($thingTypeCfg['actions']) && isset($thingTypeCfg['actions']))
				{
					$action = $thingTypeCfg['actions'][$item['dstIoTThingAction']];
					$actionLabel['text'] = $action['fn'];
				}
			}

			$listItem ['t2'][] = $actionLabel;
		}
		elseif ($item['targetType'] == TableControls::cttMQTTTopic)
		{
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [controls].*, ';
		array_push ($q, ' ioPortsDevices.fullName AS [ioPortDeviceFullName], ioPortsDevices.deviceKind AS [ioPortDeviceKind],');
		array_push ($q, ' ioPorts.portId AS [ioPortId], ioPorts.fullName AS [ioPortFullName],');
		array_push ($q, ' [things].fullName AS [thingName], [things].thingKind');
		array_push ($q, ' FROM [mac_iot_controls] AS [controls]');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesIOPorts] AS ioPorts ON [controls].dstIOPort = ioPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS ioPortsDevices ON ioPorts.device = ioPortsDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_things] AS [things] ON [controls].dstIoTThing = [things].ndx');
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

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('targetType');
					$this->addSeparator(self::coH2);

					if ($this->recData['targetType'] == TableControls::cttIotBoxIOPort)
					{
						$this->addColumnInput('dstIOPort');
					}
					elseif ($this->recData['targetType'] == TableControls::cttIoTThingAction)
					{
						$this->addColumnInput('dstIoTThing');
						$this->addColumnInput('dstIoTThingAction');
					}
					elseif ($this->recData['targetType'] == TableControls::cttMQTTTopic)
					{
						$this->addColumnInput('mqttTopic');
						$this->addColumnInput('mqttPayloadClick');
					}

					$this->addSeparator(self::coH2);

					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('idName');
					$this->addColumnInput ('lan');
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


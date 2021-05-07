<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewGrid, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableScenariosActions
 * @package mac\iot
 */
class TableScenariosActions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.scenariosActions', 'mac_iot_scenariosActions', 'Akce scénářů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['note']];

		return $hdr;
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
 * Class ViewScenariosActions
 * @package mac\iot
 */
class ViewScenariosActions extends TableViewGrid
{
	var $scenarioNdx = 0;
	var $workingDays;

	/** @var \mac\lan\TableDevices */
	var $tableDevices;

	/** @var \mac\iot\TableThings */
	var $tableThings;


	public function init ()
	{
		parent::init();

		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableThings = $this->app()->table('mac.iot.things');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$g = [
			'action' => 'Akce',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);

		$this->scenarioNdx = intval($this->queryParam('scenario'));
		$this->addAddParam ('scenario', $this->scenarioNdx);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$listItem ['action'] = [];

		if ($item['actionType'] == TableScenarios::sacIotBoxIOPort)
		{
			if ($item['ioPortDeviceFullName'])
				$listItem ['action'][] = ['text' => $item ['ioPortDeviceFullName'], 'class' => 'label label-default', 'icon' => $this->tableDevices->tableIcon(['deviceKind' => $item['ioPortDeviceKind']])];

			$ioPortId = ['text' => '', 'class' => 'label label-default'];
			if ($item['ioPortId'])
				$ioPortId['text'] = $item['ioPortId'];
			if ($item['ioPortFullName'])
				$ioPortId['suffix'] = $item['ioPortFullName'];

			$listItem ['action'][] = $ioPortId;
		}
		elseif ($item['actionType'] == TableScenarios::sacIoTThingAction)
		{
			if ($item['thingName'])
				$listItem ['action'][] = ['text' => $item ['thingName'], 'class' => 'label label-default', 'icon' => $this->tableThings->tableIcon([/*'icon' => $item['thingIcon'],*/ 'thingKind' => $item['thingKind']])];

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

			$listItem ['action'][] = $actionLabel;
		}

		$listItem ['note'] = $item['note'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [actions].*,';
		array_push ($q, ' ioPortsDevices.fullName AS [ioPortDeviceFullName], ioPortsDevices.deviceKind AS [ioPortDeviceKind],');
		array_push ($q, ' ioPorts.portId AS [ioPortId], ioPorts.fullName AS [ioPortFullName],');
		array_push ($q, ' [things].fullName AS [thingName], [things].thingKind');
		array_push ($q, ' FROM [mac_iot_scenariosActions] AS [actions]');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesIOPorts] AS ioPorts ON [actions].dstIOPort = ioPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS ioPortsDevices ON ioPorts.device = ioPortsDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_things] AS [things] ON [actions].dstIoTThing = [things].ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND actions.[scenario] = %i', $this->scenarioNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [note] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [actions].[rowOrder], [actions].[ndx]');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailScenarioAction
 * @package mac\iot
 */
class ViewDetailScenarioAction extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'cfg #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormScenarioAction
 * @package mac\iot
 */
class FormScenarioAction extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-cogs'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('actionType');
					if ($this->recData['actionType'] == TableScenarios::sacIotBoxIOPort)
					{
						$this->addColumnInput('dstIOPort');
					}
					elseif ($this->recData['actionType'] == TableScenarios::sacIoTThingAction)
					{
						$this->addColumnInput('dstIoTThing');
						$this->addColumnInput('dstIoTThingAction');
					}

					$this->addSeparator(self::coH2);
					$this->addColumnInput ('note');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

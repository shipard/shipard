<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


/**
 * Class TableSCPlacements
 * @package mac\iot
 */
class TableSCPlacements extends DbTable
{
	CONST ptWorkplace = 0, ptLANDevice = 1, ptLANRack = 2;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.scPlacements', 'mac_iot_scPlacements', 'Zařazení senzorů a ovládácích prvků do aplikace');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
	//	$hdr ['info'][] = ['class' => 'info', 'value' => '#'.$recData['ndx'].'.'.$recData['uid']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		//return $this->app()->cfgItem ('mac.control.controlsKinds.'.$recData['controlKind'].'.icon', 'x-cog');

		return parent::tableIcon($recData, $options);
	}
}


/**
 * Class ViewSCPlacements
 * @package mac\iot
 */
class ViewSCPlacements extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		//$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($item['placementTo'] == TableSCPlacements::ptWorkplace)
		{
			$listItem ['t2'][] = ['text' => $item['workplaceName'], 'class' => 'label label-default', 'icon' => 'icon-sun-o'];
		}
		elseif ($item['placementTo'] == TableSCPlacements::ptLANDevice)
		{
			if ($item['lanDevice'])
				$listItem ['t2'][] = ['text' => $item['lanDeviceName'], 'class' => 'label label-default', 'icon' => 'tables/mac.lan.devices'];
			else
				$listItem ['t2'][] = ['text' => '!!!', 'class' => 'label label-default e10-error', 'icon' => 'tables/mac.lan.racks'];
		}
		elseif ($item['placementTo'] == TableSCPlacements::ptLANRack)
		{
			if ($item['lanRack'])
				$listItem ['t2'][] = ['text' => $item['rackName'], 'class' => 'label label-default', 'icon' => 'tables/mac.lan.racks'];
			else
				$listItem ['t2'][] = ['text' => '!!!', 'class' => 'label label-default e10-error', 'icon' => 'tables/mac.lan.racks'];
		}


	if ($item['sensor'])
		$listItem ['t2'][] = ['text' => $item['sensorName'], 'class' => 'label label-info', 'icon' => 'tables/mac.iot.sensors'];
	else
		$listItem ['t2'][] = ['text' => '!!!', 'class' => 'label label-info e10-error', 'icon' => 'tables/mac.iot.sensors'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [placements].*,';
		array_push ($q, ' workplaces.name AS [workplaceName],');
		array_push ($q, ' racks.[fullName] AS [rackName], racks.[id] AS [rackId],');
		array_push ($q, ' lanDevices.[fullName] AS [lanDeviceName], lanDevices.[id] AS [lanDeviceId],');
		array_push ($q, ' sensors.[fullName] AS [sensorName], sensors.[idName] AS [sensorId]');
		array_push ($q, ' FROM [mac_iot_scPlacements] AS [placements]');
		array_push ($q, ' LEFT JOIN [terminals_base_workplaces] AS [workplaces] ON [placements].workplace = [workplaces].ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_racks] AS [racks] ON [placements].lanRack = [racks].ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS [lanDevices] ON [placements].lanDevice = [lanDevices].ndx');
		array_push ($q, ' LEFT JOIN [mac_iot_sensors] AS [sensors] ON [placements].sensor = [sensors].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [placements].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [workplaces].[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'placements.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSCPlacement
 * @package mac\iot
 */
class FormSCPlacement  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('placementTo');

					$this->addSeparator(self::coH2);

					if ($this->recData['placementTo'] === TableSCPlacements::ptWorkplace)
					{
						$this->addColumnInput('workplace');
						$this->addColumnInput('mainMenu');
					}
					elseif ($this->recData['placementTo'] === TableSCPlacements::ptLANDevice)
					{
						$this->addColumnInput('lanDevice');
					}
					elseif ($this->recData['placementTo'] === TableSCPlacements::ptLANRack)
					{
						$this->addColumnInput('lanRack');
					}

					$this->addColumnInput('sensor');

					//$this->addSeparator(self::coH2);
					//$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSCPlacement
 * @package mac\iot
 */
class ViewDetailSCPlacement extends TableViewDetail
{
}


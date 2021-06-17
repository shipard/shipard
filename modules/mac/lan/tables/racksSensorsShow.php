<?php

namespace mac\lan;


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


/**
 * Class TableRacksSensorsShow
 * @package mac\lan
 */
class TableRacksSensorsShow extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.racksSensorsShow', 'mac_lan_racksSensorsShow', 'Senzory zobrazované u zařízení');
	}
}


/**
 * Class FormRackSensorShow
 * @package mac\lan
 */
class FormRackSensorShow extends TableForm
{
	var $ownerRecData = NULL;

	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->addColumnInput ('sensor');
			$this->addColumnInput ('sensorLabel');
		$this->closeForm ();
	}
}


/**
 * Class ViewRacksSensorsShow
 * @package mac\lan
 */
class ViewRacksSensorsShow extends TableView
{
}


/**
 * Class ViewRacksSensorsShowFormList
 * @package mac\lan
 */
class ViewRacksSensorsShowFormList extends \e10\TableViewGrid
{
	var $rack = 0;

	public function init ()
	{
		parent::init();


		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->rack = intval($this->queryParam('rack'));
		$this->addAddParam('rack', $this->rack);

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
		array_push ($q, ' FROM [mac_lan_racksSensorsShow] AS sensorsToShow');
		array_push ($q, ' LEFT JOIN [mac_iot_sensors] AS sensors ON sensorsToShow.sensor = sensors.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND sensorsToShow.[rack] = %i', $this->rack);

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
 * Class ViewRacksSensorsShowListDetail
 * @package mac\lan
 */
class ViewRacksSensorsShowListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'port #'.$this->item['ndx']]]);
	}
}

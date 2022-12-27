<?php

namespace mac\iot;
use \Shipard\Table\DbTable, \Shipard\Viewer\TableViewGrid;


/**
 * class TableSensorsValuesHistory
 */
class TableSensorsValuesHistory extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('mac.iot.sensorsValuesHistory', 'mac_iot_sensorsValuesHistory', 'Historie hodnot senzorů');
	}
}


/**
 * class ViewSensorsValuesHistory
 */
class ViewSensorsValuesHistory extends TableViewGrid
{
  var $sensorNdx = 0;

	public function init ()
	{
    $this->sensorNdx = intval($this->queryParam ('sensorNdx'));

		$this->gridEditable = FALSE;

		parent::init();

		$this->objectSubType = self::vsDetail;
		$this->enableDetailSearch = FALSE;

		$g = [
      'ts' => 'Datum a čas',
      'value' => ' Hodnota',
		];

		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['ts'] = $item['time']->format('Y-m-d H:i:s');
		$listItem ['value'] = $item['valueNum'];

    $bt = [];
    $bt [] = ['id' => 'all', 'title' => 'Vše', 'active' => 1];
    $bt [] = ['id' => 'changes', 'title' => 'Změny', 'active' => 0];
    $this->setBottomTabs ($bt);

		return $listItem;
	}

	public function selectRows ()
	{
    $bt = $this->bottomTabId ();

    $q = [];
		array_push ($q, 'SELECT [values].* ');
    array_push ($q, ' FROM [mac_iot_sensorsValuesHistory] AS [values]');
    array_push ($q, ' WHERE 1');

    if ($this->sensorNdx)
      array_push ($q, ' AND [sensor] = %i', $this->sensorNdx);

    if ($bt === 'changes')
      array_push ($q, ' AND [valueChanged] = %i', 1);

		array_push ($q, ' ORDER BY [ndx] DESC ');
    array_push ($q, $this->sqlLimit());

    $this->runQuery ($q);
	}
}

<?php

namespace mac\iot;
use \e10\DbTable;


/**
 * Class TableSensorsValues
 * @package mac\iot
 */
class TableSensorsValues extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('mac.iot.sensorsValues', 'mac_iot_sensorsValues', 'Hodnoty senzorů');
	}
}

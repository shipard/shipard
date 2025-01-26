<?php

namespace mac\iot;
use \Shipard\Table\DbTable;


/**
 * class TableDevicesInfoPwr
 */
class TableDevicesInfoPwr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.devicesInfoPwr', 'mac_iot_devicesInfoPwr', 'Informace o napájení zařízení');
	}
}

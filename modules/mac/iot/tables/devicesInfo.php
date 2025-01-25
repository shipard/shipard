<?php

namespace mac\iot;


use \Shipard\Table\DbTable;

/**
 * Class TableDevicesInfo
 */
class TableDevicesInfo extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.devicesInfo', 'mac_iot_devicesInfo', 'Informace o zařízení');
	}
}

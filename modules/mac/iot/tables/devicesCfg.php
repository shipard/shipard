<?php

namespace mac\iot;


use \Shipard\Table\DbTable;


/**
 * Class TableDevicesCfg
 */
class TableDevicesCfg extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.devicesCfgIoTBoxes', 'mac_iot_devicesCfg', 'Konfigurace IoT zařízení');
	}
}

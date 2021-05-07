<?php

namespace mac\lan;


use \e10\DbTable;


/**
 * Class TableDevicesCfgIotBoxes
 * @package mac\lan
 */
class TableDevicesCfgIotBoxes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesCfgIoTBoxes', 'mac_lan_devicesCfgIoTBoxes', 'Konfigurace IoT Boxů');
	}
}

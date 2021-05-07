<?php

namespace mac\lan;


use \e10\DbTable;


/**
 * Class TableDevicesCfgNodes
 * @package mac\lan
 */
class TableDevicesCfgNodes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesCfgNodes', 'mac_lan_devicesCfgNodes', 'Konfigurace Shipard Nodes');
	}
}

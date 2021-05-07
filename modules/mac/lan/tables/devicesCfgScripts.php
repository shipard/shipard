<?php

namespace mac\lan;


use \e10\DbTable;


/**
 * Class TableDevicesCfgScripts
 * @package mac\lan
 */
class TableDevicesCfgScripts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesCfgScripts', 'mac_lan_devicesCfgScripts', 'Konfigurační skripty zařízení');
	}
}

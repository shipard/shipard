<?php

namespace mac\lan;


use \e10\DbTable;

/**
 * Class TableDevicesInfo
 * @package mac\lan
 */
class TableDevicesInfo extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesInfo', 'mac_lan_devicesInfo', 'Informace o zařízení');
	}
}

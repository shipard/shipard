<?php

namespace mac\swlan;
use \e10\DbTable;


/**
 * Class TableDevicesSW
 * @package mac\swlan
 */
class TableDevicesSW extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('mac.swlan.devicesSW', 'mac_swlan_devicesSW', 'SW na zařízeních');
	}
}

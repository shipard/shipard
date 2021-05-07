<?php

namespace mac\swlan;
use \e10\DbTable;


/**
 * Class TableDevicesSWHistory
 * @package mac\swlan
 */
class TableDevicesSWHistory extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('mac.swlan.devicesSWHistory', 'mac_swlan_devicesSWHistory', 'Historie SW na zařízeních');
	}
}

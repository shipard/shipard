<?php

namespace mac\lan;
use \e10\DbTable;


/**
 * Class TableAlerts
 * @package mac\lan
 */
class TableAlertsDevices extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.alertsDevices', 'mac_lan_alertsDevices', 'Výstrahy - zařízení');
	}
}


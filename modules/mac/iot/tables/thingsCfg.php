<?php

namespace mac\iot;
use \e10\DbTable;


/**
 * Class TableThingsCfg
 * @package mac\iot
 */
class TableThingsCfg extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.thingsCfg', 'mac_iot_thingsCfg', 'Konfigurace IoT Věcí');
	}
}

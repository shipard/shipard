<?php

namespace mac\iot;
use \Shipard\Table\DbTable;


/**
 * Class TableSetupsStates
 */
class TableSetupsStates extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('mac.iot.setupsStates', 'mac_iot_setupsStates', 'Stavy Sestav');
	}
}

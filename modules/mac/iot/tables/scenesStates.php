<?php

namespace mac\iot;
use \Shipard\Table\DbTable;


/**
 * Class TableScenesStates
 */
class TableScenesStates extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('mac.iot.scenesStates', 'mac_iot_scenesStates', 'Stavy Scén');
	}
}

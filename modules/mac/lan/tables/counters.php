<?php

namespace mac\lan;

use \E10\DbTable;


/**
 * Class TableCounters
 * @package mac\lan
 */
class TableCounters extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('mac.lan.counters', 'mac_lan_counters', 'Počitadla');
	}
}


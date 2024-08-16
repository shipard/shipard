<?php

namespace hosting\core;

use \Shipard\Table\DbTable;

/**
 * class TableHostingLog
 */
class TableHostingLog extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('hosting.core.hostingLog', 'hosting_core_hostingLog', 'Log hostingu');
	}
}

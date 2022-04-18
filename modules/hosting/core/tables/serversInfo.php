<?php

namespace hosting\core;
use \Shipard\Table\DbTable;


/**
 * @class TableServersInfo
 */
class TableServersInfo extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('hosting.core.serversInfo', 'hosting_core_serversInfo', 'Informace ze serverů');
	}
}

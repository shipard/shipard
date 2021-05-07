<?php

namespace swdev\dm;


use \E10\DbTable;


/**
 * Class TableTablesTrData
 * @package swdev\dm
 */
class TableTablesTrData extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.tablesTrData', 'swdev_dm_tablesTrData', 'Data pro lokalizaci tabulek');
	}
}

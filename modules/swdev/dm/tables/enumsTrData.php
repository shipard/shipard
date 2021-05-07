<?php

namespace swdev\dm;


use \E10\DbTable;


/**
 * Class TableEnumsTrData
 * @package swdev\dm
 */
class TableEnumsTrData extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.enumsTrData', 'swdev_dm_enumsTrData', 'Data pro lokalizaci enumů');
	}
}

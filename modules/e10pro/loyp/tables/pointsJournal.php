<?php
namespace e10pro\loyp;

use \Shipard\Table\DbTable;


/**
 * class TablePointsJournal
 */
class TablePointsJournal extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.loyp.pointsJournal', 'e10pro_loyp_pointsJournal', 'Deník věrnostních bodů');
	}
}

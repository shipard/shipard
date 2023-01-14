<?php

namespace e10pro\reports\waste_cz;

use \Shipard\Table\DbTable;


/**
 * class TableReturnRows
 */
class TableReturnRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.reports.waste_cz.returnRows', 'e10pro_reports_waste_cz_returnRows', 'Řádky hlášení o odpadech');
	}
}

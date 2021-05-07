<?php

namespace e10doc\taxes;

use \E10\DbTable;


/**
 * Class TableReportsRowsVatCS
 * @package e10doc\taxes
 */
class TableReportsRowsVatCS extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.reportsRowsVatCS', 'e10doc_taxes_reportsRowsVatCS', 'Řádky kontrolního hlášení DPH', 1108);
	}
}

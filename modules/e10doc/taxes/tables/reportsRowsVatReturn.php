<?php

namespace e10doc\taxes;

use \E10\DbTable;


/**
 * Class TableReportsRowsVatReturn
 * @package e10doc\taxes
 */
class TableReportsRowsVatReturn extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.reportsRowsVatReturn', 'e10doc_taxes_reportsRowsVatReturn', 'Řádky přiznání DPH', 1107);
	}
}

<?php

namespace e10doc\taxes;

use \Shipard\Table\DbTable;


/**
 * Class TableReportsRowsVatOSS
 */
class TableReportsRowsVatOSS extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.reportsRowsVatOSS', 'e10doc_taxes_reportsRowsVatOSS', 'Řádky DPH OSS');
	}
}

<?php

namespace e10doc\taxes;

use \E10\DbTable;


/**
 * Class TableReportsRowsVatRS
 * @package e10doc\taxes
 */
class TableReportsRowsVatRS extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.reportsRowsVatRS', 'e10doc_taxes_reportsRowsVatRS', 'Řádky souhrnného hlášení DPH', 1109);
	}
}

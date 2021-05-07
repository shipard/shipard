<?php

namespace E10Doc\CmnBkp {

use \E10\Application, \E10\utils, \E10\TableView;
use \E10\HeaderData;
use \E10\DbTable;


/**
 * Řádky daňového přiznání
 *
 */

class TableRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.tax.rows", "e10doc_tax_rows", "Řádky daňového přiznání");
	}
}

} // namespace E10Doc\CmnBkp


<?php

namespace e10doc\taxes;

use e10\DbTable;


/**
 * Class TableReportsData
 * @package e10doc\taxes
 */
class TableReportsData extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.reportsData', 'e10doc_taxes_reportsData', 'Podklady pro daňová přiznání', 1104);
	}
}


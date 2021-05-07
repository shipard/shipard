<?php

namespace e10doc\taxes;

use e10\DbTable;

/**
 * Class TableFilingFiles
 * @package e10doc\taxes
 */
class TableFilingFiles extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.filingFiles', 'e10doc_taxes_filingFiles', 'Soubory podání daňových přiznání a přehledů');
	}
}


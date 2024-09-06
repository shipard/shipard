<?php

namespace e10pro\vendms;

use \Shipard\Table\DbTable;


/**
 * Class TableVendmsJournal
 */
class TableVendmsJournal extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.vendms.vendmsJournal', 'e10pro_vendms_vendmsJournal', 'Deník pohybů automatu');
	}
}


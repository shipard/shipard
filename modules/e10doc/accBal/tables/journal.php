<?php

namespace e10doc\accBal;

use \Shipard\Table\DbTable;



/**
 * class TableJournal
 */
class TableJournal extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.accBal.journal', 'e10doc_accBal_journal', 'Saldokonto');
	}
}

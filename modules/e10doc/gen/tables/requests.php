<?php

namespace e10doc\gen;
use \Shipard\Table\DbTable;


/**
 * class TableRequests
 */
class TableRequests extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.gen.requests', 'e10doc_gen_requests', 'Požadavky na generování dokladů');
	}
}

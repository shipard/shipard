<?php

namespace e10doc\base;
use \Shipard\Table\DbTable;


/**
 * Class TableSendReportsQueue
 */
class TableSendReportsQueue extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.sendReportsQueue', 'e10doc_base_sendReportsQueue', 'Fronta odesílání dokladů');
	}
}

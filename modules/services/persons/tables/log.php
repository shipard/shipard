<?php

namespace services\persons;
use \Shipard\Table\DbTable;


/**
 * Class TableLog
 */
class TableLog extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.log', 'services_persons_log', 'Log');
	}
}

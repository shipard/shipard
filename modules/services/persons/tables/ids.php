<?php

namespace services\persons;
use \Shipard\Table\DbTable;

/**
 * Class TableIds
 */
class TableIds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.ids', 'services_persons_ids', 'ID');
	}
}

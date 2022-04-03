<?php

namespace services\persons;

use \Shipard\Table\DbTable;


/**
 * Class TableRegsData
 */
class TableRegsData extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.regsData', 'services_persons_regsData', 'Data Osob z registrů');
	}
}

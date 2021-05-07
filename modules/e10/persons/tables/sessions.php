<?php

namespace E10\Persons;

use E10\DbTable;


/**
 * Class TableSessions
 * @package E10\Persons
 */
class TableSessions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.sessions', 'e10_persons_sessions', 'Sezení');
	}
}


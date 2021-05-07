<?php

namespace E10\Persons;
use \E10\DbTable;


/**
 * Class TablePersonsGroups
 * @package E10\Persons
 */
class TablePersonsGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.personsgroups', 'e10_persons_personsgroups', 'Skupiny osob');
	}
}


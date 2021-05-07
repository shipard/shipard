<?php

namespace e10\persons;

use \E10\DbTable;


/**
 * Class TablePersonsLastUse
 * @package e10\persons
 */
class TablePersonsLastUse extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10.persons.personsLastUse', 'e10_persons_personsLastUse', 'Poslední použití Osob');
	}
}

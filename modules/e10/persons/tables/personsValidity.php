<?php

namespace e10\persons;

use \E10\DbTable;


/**
 * Class TablePersonsValidity
 * @package e10\persons
 */
class TablePersonsValidity extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10.persons.personsValidity', 'e10_persons_personsValidity', 'Správnost Osob');
	}
}

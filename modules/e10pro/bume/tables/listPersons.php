<?php

namespace e10pro\bume;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * class TableListPersons
 */
class TableListPersons extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10pro.bume.listPersons', 'e10pro_wkf_listPersons', 'Osoby v seznamu');
	}
}


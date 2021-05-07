<?php

namespace E10\Persons;
require_once __DIR__ . '/../../base/base.php';
use \E10\DbTable, \E10\TableForm;


/**
 * Tabulka Vazby Osob
 *
 */

class TableConnections extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.persons.connections", "e10_persons_connections", "Vazby osob");
	}
}

/*
 * FormConnection
 *
 */

class FormConnection extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow ();
				$this->addColumnInput ("connectionType", TableForm::coColW3);
				$this->addColumnInput ("connectedPerson", TableForm::coColW6);
				$this->addColumnInput ("note", TableForm::coColW3);
			$this->closeRow ();
		$this->closeForm ();
	}
}


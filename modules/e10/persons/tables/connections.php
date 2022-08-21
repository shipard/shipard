<?php

namespace e10\persons;
use \Shipard\Table\DbTable, \Shipard\Form\TableForm;


/**
 * class TableConnections
 */
class TableConnections extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.connections', 'e10_persons_connections', 'Vazby osob');
	}
}

/*
 * class FormConnection
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


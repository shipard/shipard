<?php

namespace e10mnf\core;

use \Shipard\Table\DbTable, \Shipard\Form\TableForm;


/**
 * Class TableWorkOrdersPersons
 */
class TableWorkOrdersPersons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.core.workOrdersPersons', 'e10mnf_core_workOrdersPersons', 'Osoby zakázek');
	}
}


/**
 * Class FormPerson
 */
class FormPerson extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

		$this->openForm (TableForm::ltGrid);
      $this->openRow ();
        $this->addColumnInput ('person', TableForm::coColW12);
      $this->closeRow ();

      $this->openRow ();
        $this->addColumnInput ('validFrom', TableForm::coColW6);
        $this->addColumnInput ('validTo', TableForm::coColW6);
      $this->closeRow ();
		$this->closeForm ();
	}
}


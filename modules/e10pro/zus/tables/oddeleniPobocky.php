<?php

namespace e10pro\zus;

use \Shipard\Form\TableForm;
use \Shipard\Table\DbTable;


/**
 * class TableOddeleniPobocky
 */
class TableOddeleniPobocky extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.oddeleniPobocky', 'e10pro_zus_oddeleniPobocky', 'Pobočky oddělení');
	}
}


/**
 * FormRadekOddeleniPobocka
 */
class FormRadekOddeleniPobocka extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('pobocka', TableForm::coColW5);
        $this->addColumnInput ('stop', TableForm::coColW7);
			$this->closeRow();
		$this->closeForm ();
	}
}

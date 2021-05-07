<?php

namespace e10pro\canteen;
use \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TablePersonsOptionsFoods
 * @package e10pro\canteen
 */
class TablePersonsOptionsFoods extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.personsOptionsFoods', 'e10pro_canteen_personsOptionsFoods', 'Nastavená jídla');
	}
}


/**
 * Class FormZoneCamera
 * @package mac\base
 */
class FormPersonOptionsFood extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('name', TableForm::coColW5);
				$this->addColumnInput ('taking', TableForm::coColW7);
			$this->closeRow();
		$this->closeForm ();
	}
}


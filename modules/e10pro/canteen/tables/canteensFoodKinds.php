<?php

namespace e10pro\canteen;
use \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableCanteensFoodKinds
 * @package e10pro\canteen
 */
class TableCanteensFoodKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.canteensFoodKinds', 'e10pro_canteen_canteensFoodKinds', 'Druhy jídel');
	}
}


/**
 * Class FormZoneCamera
 * @package mac\base
 */
class FormCanteenFoodKind extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('fullName', TableForm::coColW7);
				$this->addColumnInput ('shortName', TableForm::coColW5);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('useSoup', TableForm::coColW6);
				$this->addColumnInput ('cntFoods', TableForm::coColW6);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('validFrom', TableForm::coColW6);
				$this->addColumnInput ('validTo', TableForm::coColW6);
			$this->closeRow();
		$this->closeForm ();
	}
}


<?php

namespace e10\witems;
use \E10\Application, \E10\utils, \E10\TableForm, \E10\DbTable;


/**
 * Class TableItemSuppliers
 * @package e10\witems
 */
class TableItemSuppliers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.itemSuppliers', 'e10_witems_itemSuppliers', 'Dodavatelé položek');
	}
}


/**
 * Class FormItemSupplier
 * @package e10\witems
 */
class FormItemSupplier extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('supplier', self::coColW12);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('itemId', self::coColW5);
				$this->addColumnInput ('url', self::coColW7);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('validFrom', self::coColW6);
				$this->addColumnInput ('validTo', self::coColW6);
			$this->closeRow();
		$this->closeForm ();
	}
}

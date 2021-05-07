<?php

namespace e10pro\property;

use \E10\TableForm, \E10\DbTable;


/**
 * Class TablePropertyAccessory
 * @package e10pro\property
 */
class TablePropertyAccessory extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.property.propertyAccessory', 'e10pro_property_propertyAccessory', 'Příslušenství majetku');
	}
}


/**
 * Class FormPropertyAccessory
 * @package e10pro\property
 */
class FormPropertyAccessory extends TableForm
{
	public function renderForm ()
	{
		//$ownerRecData = $this->option ('ownerRecData');
		//$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);

		$this->openForm (TableForm::ltGrid);
			$this->openRow ();
				$this->addColumnInput ('linkedProperty', TableForm::coColW12);
			$this->closeRow ();
		$this->closeForm ();
	}
}


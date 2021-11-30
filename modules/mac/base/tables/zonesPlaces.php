<?php

namespace mac\base;


use \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableZonesPlaces
 */
class TableZonesPlaces extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.base.zonesPlaces', 'mac_base_zonesPlaces', 'Místa v zónach');
	}
}


/**
 * Class FormZonePlace
 */
class FormZonePlace extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('place', TableForm::coColW12);
			$this->closeRow();
		$this->closeForm ();
	}
}


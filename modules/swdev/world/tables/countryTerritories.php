<?php

namespace swdev\world;


use \e10\TableForm, \e10\DbTable;


/**
 * Class TableCountryTerritories
 * @package swdev\world
 */
class TableCountryTerritories extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.countryTerritories', 'swdev_world_countryTerritories', 'Oblasti zemí');
	}
}


/**
 * Class FormCountryTerritory
 * @package swdev\world
 */
class FormCountryTerritory extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('territory', TableForm::coColW12);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('validFrom', TableForm::coColW6);
				$this->addColumnInput ('validTo', TableForm::coColW6);
			$this->closeRow();
		$this->closeForm ();
	}
}


<?php

namespace swdev\world;


use \e10\TableForm, \e10\DbTable;


/**
 * Class TableCountryCurrencies
 * @package swdev\world
 */
class TableCountryCurrencies extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.countryCurrencies', 'swdev_world_countryCurrencies', 'Měny zemí');
	}
}


/**
 * Class FormCountryCurrency
 * @package swdev\world
 */
class FormCountryCurrency extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
		$this->openRow();
			$this->addColumnInput ('currency', TableForm::coColW12);
		$this->closeRow();
		$this->openRow();
			$this->addColumnInput ('validFrom', TableForm::coColW6);
			$this->addColumnInput ('validTo', TableForm::coColW6);
		$this->closeRow();
		$this->closeForm ();
	}
}


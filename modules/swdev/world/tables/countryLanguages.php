<?php

namespace swdev\world;


use \e10\TableForm, \e10\DbTable;


/**
 * Class TableCountryLanguages
 * @package swdev\world
 */
class TableCountryLanguages extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.countryLanguages', 'swdev_world_countryLanguages', 'Jazyky zemí');
	}
}


/**
 * Class FormCountryLanguage
 * @package swdev\world
 */
class FormCountryLanguage extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('language', TableForm::coColW12);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('nameCommon', TableForm::coColW12);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('nameOfficial', TableForm::coColW12);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('validFrom', TableForm::coColW6);
				$this->addColumnInput ('validTo', TableForm::coColW6);
			$this->closeRow();
		$this->closeForm ();
	}
}


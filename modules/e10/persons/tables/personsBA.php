<?php

namespace e10\persons;
use \Shipard\Table\DbTable, \Shipard\Form\TableForm, \Shipard\Utils\Str;
use \Shipard\Utils\World;

/**
 * class TablePersonsBA
 */
class TablePersonsBA extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.personsBA', 'e10_persons_personsBA', 'Bankovní spojení Osob');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}
}


/**
 * class FormPersonBA
 */
class FormPersonBA extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Účet', 'icon' => 'formContacts'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('bankAccount');
          $this->addColumnInput ('validFrom');
          $this->addColumnInput ('validTo');
				$this->closeTab ();
				$this->openTab ();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

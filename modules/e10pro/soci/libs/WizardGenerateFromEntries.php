<?php

namespace e10pro\soci\libs;

use E10\Wizard, E10\TableForm;
use \Shipard\Utils\World;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';

/**
 * class WizardGenerateFromEntries
 */
class WizardGenerateFromEntries extends Wizard
{
  var $entryRecData = NULL;
  var $newPersonNdx = NULL;

  /** @var \e10\persons\TablePersons */
  var $tablePersons;
  /** @var \e10\persons\TablePersonsContacts */
  var $tablePersonsContacts;

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->generate();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->recData['entryNdx'] = $this->focusedPK;
    $this->entryRecData = $this->app()->loadItem(intval($this->focusedPK), 'e10pro.soci.entries');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('entryNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
    $this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');

    $this->entryRecData = $this->app()->loadItem(intval($this->recData['entryNdx']), 'e10pro.soci.entries');
    $this->createPerson();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vygenerovat osobu'];


		$hdr ['info'][] = ['class' => 'info', 'value' => $this->entryRecData['lastNameS'].' '.$this->entryRecData['firstNameS']];

		return $hdr;
	}

	public function createPerson ()
	{
		$newPerson ['person'] = [];
		$newPerson ['person']['company'] = 0;
		$newPerson ['person']['firstName'] = $this->entryRecData['firstName'];
		$newPerson ['person']['lastName'] = $this->entryRecData['lastName'];
		$newPerson ['person']['fullName'] = $this->entryRecData['lastName'].' '.$this->entryRecData['firstName'];
		$newPerson ['person']['docState'] = 4000;
		$newPerson ['person']['docStateMain'] = 2;

    $newPerson ['ids'][] = ['type' => 'birthdate', 'value' => $this->entryRecData ['birthday']];
    if ($this->entryRecData['email'] !== '')
      $newPerson ['contacts'][] = ['type' => 'email', 'value' => $this->entryRecData['email']];
    if ($this->entryRecData['phone'] !== '')
      $newPerson ['contacts'][] = ['type' => 'phone', 'value' => $this->entryRecData['phone']];

		$this->newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
		$this->tablePersons->docsLog ($this->newPersonNdx);

    $this->app()->db()->query('UPDATE [e10pro_soci_entries] SET [dstPerson] = %i', $this->newPersonNdx, ' WHERE [ndx] = %i', $this->entryRecData['ndx']);

		return $this->newPersonNdx;
	}
}

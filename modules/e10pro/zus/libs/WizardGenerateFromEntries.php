<?php

namespace e10pro\zus\libs;

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
    $this->entryRecData = $this->app()->loadItem(intval($this->focusedPK), 'e10pro.zus.prihlasky');

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('entryNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		$this->closeForm ();
	}

	public function generate ()
	{
    $this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');

    $this->entryRecData = $this->app()->loadItem(intval($this->recData['entryNdx']), 'e10pro.zus.prihlasky');
    $this->createStudent();

		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
		$this->stepResult['lastStep'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-play';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vygenerovat studenta a studium'];


		$hdr ['info'][] = ['class' => 'info', 'value' => $this->entryRecData['lastNameS'].' '.$this->entryRecData['firstNameS']];

		return $hdr;
	}

	public function createStudent ()
	{
		$newPerson ['person'] = [];
		$newPerson ['person']['company'] = 0;
		$newPerson ['person']['firstName'] = $this->entryRecData['firstNameS'];
		$newPerson ['person']['lastName'] = $this->entryRecData['lastNameS'];
		$newPerson ['person']['fullName'] = $this->entryRecData['lastNameS'].' '.$this->entryRecData['firstNameS'];
		$newPerson ['person']['docState'] = 4000;
		$newPerson ['person']['docStateMain'] = 2;

		$newAddress = [];
		$newAddress ['street'] = $this->entryRecData ['street'];
		$newAddress ['city'] = $this->entryRecData ['city'];
		$newAddress ['zipcode'] = $this->entryRecData ['zipcode'];
		$newAddress ['country'] = $this->app()->cfgItem ('options.core.ownerDomicile', 'cz');
		$newAddress ['worldCountry'] = World::countryNdx($this->app(), $this->app()->cfgItem ('options.core.ownerDomicile', 'cz'));

		if ($newAddress ['street'] !== '' || $newAddress ['city'] !== '' || $newAddress ['zipcode'] !== '')
			$newPerson ['address'][] = $newAddress;


		$pid = $this->entryRecData ['rodneCislo'];
		if (strlen($pid) === 10)
			$pid = substr($this->entryRecData ['rodneCislo'], 0, 6).'/'.substr($this->entryRecData ['rodneCislo'], 6);

    $newPerson ['ids'][] = ['type' => 'birthdate', 'value' => $this->entryRecData ['datumNarozeni']];
    $newPerson ['ids'][] = ['type' => 'pid', 'value' => $pid];

    $newPerson ['groups'] = ['@e10pro-zus-groups-students'];

		$this->newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
		$this->tablePersons->docsLog ($this->newPersonNdx);

    $this->app()->db()->query('UPDATE [e10pro_zus_prihlasky] SET [dstStudent] = %i', $this->newPersonNdx, ' WHERE [ndx] = %i', $this->entryRecData['ndx']);


		// -- contacs
		$this->addContact($this->newPersonNdx, 'Zákonný zástupce 1', 'M');
		$this->addContact($this->newPersonNdx, 'Zákonný zástupce 2', 'F');

		return $this->newPersonNdx;
	}

	protected function addContact($personNdx, $title, $sfx)
	{
		$newContact = [];

		$newContact['contactName'] = $this->entryRecData['fullName'.$sfx];


		if ($this->entryRecData['email'.$sfx] !== '')
			$newContact['contactEmail'] = $this->entryRecData['email'.$sfx];
		if ($this->entryRecData['phone'.$sfx] !== '')
			$newContact['contactPhone'] = $this->entryRecData['phone'.$sfx];

		if (isset($newContact['contactPhone']) || $newContact['contactEmail'])
			$newContact['flagContact'] = 1;


		if ($this->entryRecData['useAddress'.$sfx])
		{
			$newContact['flagAddress'] = 1;
			$newContact['adrStreet'] = $this->entryRecData['street'.$sfx];
			$newContact['adrCity'] = $this->entryRecData['city'.$sfx];
			$newContact['adrZipCode'] = $this->entryRecData['zipcode'.$sfx];
			$newContact['adrCountry'] = 60;
		}

		if (count($newContact))
		{
			$newContact['person'] = $personNdx;
			$newContact['contactRole'] = $title;
			$newContact['docState'] = 4000;
			$newContact['docStateMain'] = 2;

			$this->tablePersonsContacts->dbInsertRec($newContact);
		}
	}
}

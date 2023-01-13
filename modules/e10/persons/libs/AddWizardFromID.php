<?php

namespace e10\persons\libs;
use \Shipard\Form\Wizard;
use \Shipard\Utils\World;


/**
 * class AddWizardFromID
 */
class AddWizardFromID extends Wizard
{
	protected $newPersonNdx = 0;
	protected $tablePersons;

	var $docState = 4000;
	var $docStateMain = 2;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->tablePersons = $this->app()->table ('e10.persons.persons');
			$this->savePerson();
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

	public function addParams ()
	{
		$addToGroups = $this->app->testGetParam('addToGroups');
		$this->recData['addToGroups'] = $addToGroups;
		$this->addInput('addToGroups', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
	}

	public function renderFormWelcome_AddOnePerson ($sfx, $fields = FALSE)
	{
		$this->addInput('lastName'.$sfx, 'Příjmení', self::INPUT_STYLE_STRING, 0, 80);
		$this->addInput('firstName'.$sfx, 'Jméno', self::INPUT_STYLE_STRING, 0, 60);
		$this->addInput('idcn'.$sfx, 'Čislo OP', self::INPUT_STYLE_STRING, 0, 30);
		$this->addInput('birthdate'.$sfx, 'Datum narození', self::INPUT_STYLE_DATE);
		$this->addInput('street'.$sfx, 'Ulice', self::INPUT_STYLE_STRING, 0, 250);
		$this->addInput('city'.$sfx, 'Město', self::INPUT_STYLE_STRING, 0, 90);
		$this->addInput('zipcode'.$sfx, 'PSČ', self::INPUT_STYLE_STRING, 0, 20);

		if ($fields === FALSE || in_array('email', $fields))
			$this->addInput('email'.$sfx, 'E-mail', self::INPUT_STYLE_STRING, 0, 60);
		if ($fields === FALSE || in_array('phone', $fields))
			$this->addInput('phone'.$sfx, 'Telefon', self::INPUT_STYLE_STRING, 0, 20);
		if ($fields === FALSE || in_array('bankacount', $fields))
			$this->addInput('bankaccount'.$sfx, 'Číslo účtu', self::INPUT_STYLE_STRING, 0, 30);
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);
    $this->setFlag ('sidebarRefresh', 'always');

		$this->openForm ();
			$this->renderFormWelcome_AddOnePerson ('');
			$this->addParams();
		$this->closeForm ();
	}

	public function savePerson ()
	{
		$this->saveOnePerson ('');
		$this->stepResult ['close'] = 1;
	}

	public function saveOnePerson ($sfx)
	{
		$newPerson ['person'] = [];
		$newPerson ['person']['company'] = 0;
		$newPerson ['person']['firstName'] = $this->recData['firstName'.$sfx];
		$newPerson ['person']['lastName'] = $this->recData['lastName'.$sfx];
		$newPerson ['person']['fullName'] = $this->recData['lastName'.$sfx].' '.$this->recData['firstName'.$sfx];
		$newPerson ['person']['docState'] = $this->docState;
		$newPerson ['person']['docMain'] = $this->docStateMain;

		$newAddress = [];
		$newAddress ['street'] = $this->recData ['street'.$sfx];
		$newAddress ['city'] = $this->recData ['city'.$sfx];
		$newAddress ['zipcode'] = $this->recData ['zipcode'.$sfx];
		$newAddress ['country'] = $this->app()->cfgItem ('options.core.ownerDomicile', 'cz');
		$newAddress ['worldCountry'] = World::countryNdx($this->app(), $this->app()->cfgItem ('options.core.ownerDomicile', 'cz'));

		if ($newAddress ['street'] !== '' || $newAddress ['city'] !== '' || $newAddress ['zipcode'] !== '')
			$newPerson ['address'][] = $newAddress;

		if ($this->recData ['idcn'] !== '')
			$newPerson ['ids'][] = ['type' => 'idcn', 'value' => $this->recData ['idcn'.$sfx]];
		if ($this->recData ['birthdate'] !== '0000-00-00')
			$newPerson ['ids'][] = ['type' => 'birthdate', 'value' => $this->recData ['birthdate'.$sfx]];
		if (isset($this->recData ['email']) && $this->recData ['email'] !== '')
			$newPerson ['contacts'][] = ['type' => 'email', 'value' => $this->recData ['email'.$sfx]];
		if (isset($this->recData ['phone']) && $this->recData ['phone'] !== '')
			$newPerson ['contacts'][] = ['type' => 'phone', 'value' => $this->recData ['phone'.$sfx]];
		if (isset($this->recData ['bankaccount']) && $this->recData ['bankaccount'] !== '')
			$newPerson ['payments'][] = ['type' => 'bankaccount', 'value' => $this->recData ['bankaccount'.$sfx]];

		if ($this->recData['addToGroups'] != '')
			$newPerson ['groups'] = explode (',', $this->recData['addToGroups']);

		$this->newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
		$this->tablePersons->docsLog ($this->newPersonNdx);

		return $this->newPersonNdx;
	}
}

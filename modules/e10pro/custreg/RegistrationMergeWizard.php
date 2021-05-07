<?php

namespace e10pro\custreg;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \E10\TableForm, \E10\Wizard;


/**
 * Class RegistrationMergeWizard
 * @package e10pro\custreg
 */
class RegistrationMergeWizard extends Wizard
{
	protected $personRecNdx;
	protected $tablePersons;
	protected $tableRegistrations;

	public function doStep ()
	{
		$this->tablePersons = $this->app()->table ('e10.persons.persons');
		$this->tableRegistrations = $this->app()->table ('e10pro.custreg.registrations');

		if ($this->pageNumber === 1)
		{
			$this->savePerson();
			$this->stepResult ['close'] = 1;
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

	public function loadData ()
	{
		// -- person
		$this->personRecNdx = intval($this->app->testGetParam('personNdx'));
		$personRecData = $this->tablePersons->loadItem ($this->personRecNdx);
		$this->recData['personndx'] = $this->personRecNdx;
		$this->addInput('personndx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);


		$personRegData = $this->tableRegistrations->loadRegPerson ($personRecData, $this->tablePersons);
		$personRegData['bankaccount'] = $personRegData['bankAccount'];
		foreach ($personRegData as $id => $v)
		{
			if ($id === 'properties' || $id === 'address')
				continue;
			$this->recData[$id] = $v;
			$this->recData[$id . 'old'] = $v;
		}

		foreach ($personRegData['properties'] as $id => $p)
		{
			$this->recData[$id . 'ndx'] = $p['ndx'];
			$this->addInput($id . 'ndx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		}

		if (isset($personRegData['address']))
		{
			$this->recData['addressndx'] = $personRegData['address']['ndx'];
			$this->addInput('addressndx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		}

		// -- registration
		$registrationNdx = intval($this->app->testGetParam('registrationNdx'));
		$this->recData['registrationndx'] = $registrationNdx;
		$this->addInput('registrationndx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

		$registrationData = $this->tableRegistrations->loadItem ($registrationNdx);
		$registrationData['bankaccount'] = $registrationData['bankAccount'];
		foreach ($registrationData as $id => $v)
		{
			if ($v !== '')
				$this->recData[$id] = $v;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->loadData();
			$this->layoutOpen (TableForm::ltHorizontal);
				$this->layoutOpen (TableForm::ltForm);
					$sfx = '';
					$this->addStatic('Nové hodnoty', TableForm::coH1);
					$this->addInput('lastName'.$sfx, 'Příjmení', self::INPUT_STYLE_STRING, 0, 80);
					$this->addInput('firstName'.$sfx, 'Jméno', self::INPUT_STYLE_STRING, 0, 60);
					$this->addInput('birthdate'.$sfx, 'Datum narození', self::INPUT_STYLE_DATE);
					$this->addInput('idcn'.$sfx, 'Čislo OP', self::INPUT_STYLE_STRING, 0, 30);
					$this->addInput('street'.$sfx, 'Ulice', self::INPUT_STYLE_STRING, 0, 250);
					$this->addInput('city'.$sfx, 'Město', self::INPUT_STYLE_STRING, 0, 90);
					$this->addInput('zipcode'.$sfx, 'PSČ', self::INPUT_STYLE_STRING, 0, 20);
					$this->addInput('email'.$sfx, 'E-mail', self::INPUT_STYLE_STRING, 0, 60);
					$this->addInput('phone'.$sfx, 'Telefon', self::INPUT_STYLE_STRING, 0, 20);
					$this->addInput('bankaccount'.$sfx, 'Číslo účtu', self::INPUT_STYLE_STRING, 0, 30);
				$this->layoutClose ('width50');

				$this->layoutOpen (TableForm::ltForm);
					$sfx = 'old';
					$this->addStatic('Staré hodnoty', TableForm::coH1);
					$this->addInput('lastName'.$sfx, '-', self::INPUT_STYLE_STRING, TableForm::coReadOnly, 80);
					$this->addInput('firstName'.$sfx, '-', self::INPUT_STYLE_STRING, TableForm::coReadOnly, 60);
					$this->addInput('birthdate'.$sfx, '-', self::INPUT_STYLE_DATE, TableForm::coReadOnly);
					$this->addInput('idcn'.$sfx, '-', self::INPUT_STYLE_STRING, TableForm::coReadOnly, 30);
					$this->addInput('street'.$sfx, '-', self::INPUT_STYLE_STRING, TableForm::coReadOnly, 250);
					$this->addInput('city'.$sfx, '-', self::INPUT_STYLE_STRING, TableForm::coReadOnly, 90);
					$this->addInput('zipcode'.$sfx, '-', self::INPUT_STYLE_STRING, 0, 20);
					$this->addInput('email'.$sfx, '-', self::INPUT_STYLE_STRING, 0, 60);
					$this->addInput('phone'.$sfx, '-', self::INPUT_STYLE_STRING, 0, 20);
					$this->addInput('bankaccount'.$sfx, '-', self::INPUT_STYLE_STRING, 0, 30);
				$this->layoutClose ('width50');
			$this->layoutClose ();
		$this->closeForm ();
	}

	public function savePerson ()
	{
		// -- person
		$personRec = ['firstName' => $this->recData['firstName'], 'lastName' => $this->recData['lastName']];
		$this->app()->db()->query ('UPDATE [e10_persons_persons] SET ', $personRec, ' WHERE ndx = %i', $this->recData['personndx']);

		// -- properties
		$this->savePersonProperty ('contacts', 'email');
		$this->savePersonProperty ('contacts', 'phone');

		$this->savePersonProperty ('ids', 'birthdate');
		$this->savePersonProperty ('ids', 'idcn');

		$this->savePersonProperty ('payments', 'bankaccount');

		// -- address
		$address = ['street' => $this->recData['street'], 'city' => $this->recData['city'], 'zipcode' => $this->recData['zipcode']];
		if (isset ($this->recData['addressndx']) && intval($this->recData['addressndx']))
		{ // update
			$this->app()->db()->query ('UPDATE [e10_persons_address] SET ', $address, ' WHERE ndx = %i', $this->recData['addressndx']);
		}
		else
		{ // insert
			$address['tableid'] = 'e10.persons.persons';
			$address['recid'] = $this->recData['personndx'];
			$this->app()->db()->query ('INSERT INTO [e10_persons_address] ', $address);
		}

		$this->tablePersons->docsLog ($this->recData['personndx']);


		// -- registration
		$this->app()->db()->query ('UPDATE [e10pro_custreg_registrations] SET docState = 9000, docStateMain = 5 WHERE ndx = %i', $this->recData['registrationndx']);
		$this->tableRegistrations->docsLog ($this->recData['registrationndx']);
	}

	public function savePersonProperty ($gid, $pid)
	{
		if (isset ($this->recData[$pid.'ndx']))
		{ // update
			if ($pid === 'birthdate')
				$item = ['valueString' => $this->recData[$pid], 'valueDate' => $this->recData[$pid]];
			else
				$item = ['valueString' => $this->recData[$pid]];

			$this->app()->db()->query ('UPDATE [e10_base_properties] SET ', $item, ' WHERE ndx = %i', $this->recData[$pid.'ndx']);
		}
		else
		{ // insert
			if ($pid === 'birthdate')
				$item = ['valueString' => $this->recData[$pid], 'valueDate' => $this->recData[$pid]];
			else
				$item = ['valueString' => $this->recData[$pid]];

			$item['group'] = $gid;
			$item['property'] = $pid;
			$item['tableid'] = 'e10.persons.persons';
			$item['recid'] = $this->recData['personndx'];

			$this->app()->db()->query ('INSERT INTO [e10_base_properties] ', $item);
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-code-fork';
		$hdr ['info'][] = ['class' => 'title', 'value' => $this->recData ['firstName'].' '.$this->recData ['lastName']];

		return $hdr;
	}
}

<?php

namespace e10pro\custreg;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';

use \E10\TableForm;


/**
 * Class RegistrationAddWizard
 * @package e10pro\custreg
 */
class RegistrationAddWizard extends \e10\persons\libs\AddWizardFromID
{
	public function addParams ()
	{
		parent::addParams();

		$registrationNdx = $this->app->testGetParam('registrationNdx');
		$this->recData['registrationNdx'] = $registrationNdx;
		$this->addInput('registrationNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

		$r = $this->app()->loadItem($registrationNdx, 'e10pro.custreg.registrations');

		$this->recData['firstName'] = $r['firstName'];
		$this->recData['lastName'] = $r['lastName'];
		$this->recData['street'] = $r['street'];
		$this->recData['city'] = $r['city'];
		$this->recData['zipcode'] = $r['zipcode'];
		$this->recData['idcn'] = $r['idcn'];
		$this->recData['email'] = $r['email'];
		$this->recData['phone'] = $r['phone'];
		$this->recData['birthdate'] = $r['birthDate'];
		$this->recData['bankaccount'] = $r['bankAccount'];
	}

	public function savePerson ()
	{
		parent::savePerson ();

		// -- registration
		$this->app()->db()->query ('UPDATE [e10pro_custreg_registrations] SET docState = 9000, docStateMain = 5 WHERE ndx = %i', $this->recData['registrationNdx']);
		$tableRegistrations = $this->app()->table ('e10pro.custreg.registrations');
		$tableRegistrations->docsLog ($this->recData['registrationNdx']);
	}
}

<?php

namespace e10pro\custreg;

/**
 * Class RegistrationWebForm
 * @package e10pro\custreg
 */
class RegistrationWebForm extends \E10\WebForm
{
	var $valid = FALSE;

	public function fields ()
	{
		return ['firstName', 'lastName', 'birthDate', 'idcn', 'street', 'city', 'zipcode', 'email', 'bankAccount', 'phone'];
	}

	public function createFormCode ($options = 0)
	{
		$c = "<form class='form-horizontal' method='POST'>";
		$c .= "<input type='hidden' name='webFormState' value='1'/>";
		$c .= "<input type='hidden' name='webFormId' value='e10.web.contactForm'/>";

		$c .= $this->addFormInput ('Jméno', 'text', 'firstName');
		$c .= $this->addFormInput ('Příjmení', 'text', 'lastName');
		$c .= $this->addFormInput ('Datum narození', 'text', 'birthDate');
		$c .= $this->addFormInput ('Číslo OP', 'text', 'idcn');
		$c .= $this->addFormInput ('Ulice', 'text', 'street');
		$c .= $this->addFormInput ('Obec', 'text', 'city');
		$c .= $this->addFormInput ('PSČ', 'text', 'zipcode');

		$c .= $this->addFormInput ('E-mail', 'text', 'email');
		$c .= $this->addFormInput ('Mobil', 'text', 'phone');
		$c .= $this->addFormInput ('Číslo účtu', 'text', 'bankAccount');

		$c .= "<div class='form-group'><div class='col-sm-offset-2 col-sm-10'><button type='submit' class='btn btn-primary'>Odeslat registraci</button></div></div>";
		$c .= '</form>';

		return $c;
	}

	public function validate ()
	{
		$this->valid = TRUE;

		$this->checkValidField('firstName', 'Jméno není vyplněno');
		$this->checkValidField('lastName', 'Příjmení není vyplněno');
		$this->checkValidField('birthDate', 'Datum narození není vyplněno');
		$this->checkValidField('idcn', 'Číslo OP není vyplněno');
		$this->checkValidField('street', 'Ulice není vyplněna');
		$this->checkValidField('city', 'Obec není vyplněna');
		$this->checkValidField('zipcode', 'PSČ není vyplněno');
		$this->checkValidField('email', 'E-mail není vyplněn');
		//$this->checkValidField('phone', 'Mobil není vyplněn');
		$this->checkValidField('bankAccount', 'Číslo účtu není vyplněno');

		return $this->valid;
	}

	public function checkValidField ($id, $msg)
	{
		if ($this->app->testPostParam ($id) == '')
		{
			$this->formErrors [$id] = $msg;
			$this->valid = FALSE;
		}

		if ($id === 'birthDate')
		{
			$bdStr = $this->app->testPostParam ($id);
			$dt = \DateTime::createFromFormat("d.m.Y", $bdStr);
			if ($dt === false || array_sum($dt->getLastErrors()))
			{
				$this->formErrors [$id] = "Datum musí být zadáno jako DD.MM.RRRR";
				$this->valid = FALSE;
			}
		}
	}

	public function doIt ()
	{
		$requestData = json_encode ($this->data);
		$requestId = sha1($requestData . time() . mt_rand(100000, 999999));

		$dateCreated = new \DateTime();
		$dateValid = new \DateTime();
		$dateValid->add (new \DateInterval('P30D'));

		$birthDate = \DateTime::createFromFormat("d.m.Y", $this->data['birthDate']);
		$country = 'cz';

		$newRegistration = [
			'firstName' => $this->data['firstName'], 'lastName' => $this->data['lastName'],
			'street' => $this->data['street'], 'city' => $this->data['city'], 'zipcode' => $this->data['zipcode'], 'country' => $country,
			'email' => $this->data['email'], 'phone' => $this->data['phone'], 'bankAccount' => $this->data['bankAccount'],
			'idcn' => $this->data['idcn'], 'birthDate' => $birthDate,

			'requestId' => $requestId,
			'created' => $dateCreated, 'validTo' => $dateValid,
			'addressCreate' => $_SERVER ['REMOTE_ADDR'],
			'docState' => 1000, 'docStateMain' => 0
		];
		$this->app->db->query ("INSERT INTO [e10pro_custreg_registrations]", $newRegistration);

		return TRUE;
	}
}

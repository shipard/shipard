<?php

namespace E10\Persons;

use E10\DbTable, \E10\TableForm;


/**
 * Class TableUsersPasswords
 * @package E10\Persons
 */
class TableUsersPasswords extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.userspasswords', 'e10_persons_userspasswords', 'Hesla uživatelů');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec($recData);

		if (isset($recData['pwType']) && $recData['pwType'] == 1 && isset($recData['person']) && $recData['person'])
		{
			$person = $this->loadItem($recData['person'], 'e10_persons_persons');

			$apiKeySrc = '';
			for ($step = 0; $step < mt_rand (10, 50); $step++)
				$apiKeySrc .= base_convert (mt_rand (1000000, 9999999), 10, 35).base_convert (mt_rand (1000000, 9999999), 10, 35);

			$newPwd = base_convert (mt_rand (1000000, 9999999), 10, 35).base_convert (mt_rand (1000000, 9999999), 10, 35);
			$salt = sha1 ('--' . time() . "--" . $person['fullName'] . '--' . mt_rand () . $person['loginHash']);
			$password = sha1 ($newPwd . $salt);

			$recData['salt'] = $salt;
			$recData['password'] = $password;
			$recData['emailHash'] = sha1($apiKeySrc);
		}
	}
}


/**
 * Class FormAPIKey
 * @package E10\Persons
 */
class FormAPIKey extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
		if ($this->recData['pwType'] == 1)
		{
			$this->addInput('emailHash', 'API KEY', TableForm::INPUT_STYLE_STRING, 0, 40);
		}
		$this->closeForm ();
	}
}

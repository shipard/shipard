<?php

namespace e10\users\libs;
use \Shipard\Form\Wizard, \Shipard\Utils\Str;


/**
 * class ResendRequestWizard
 */
class AddUserWizard extends Wizard
{
	var $tableRequests;
	var $requestNdx = 0;
	var $requestRecData;
  var \e10\users\libs\SendRequestEngine $sendRequestEngine;

	function init()
	{
		//parent::init();
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->sendRequest();
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
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$enum = [];
		$uis = $this->app()->cfgItem('e10.ui.uis');
		foreach ($uis as $uiId => $uiCfg)
		{
			$enum[$uiCfg['ndx']] = $uiCfg['fn'];
			if (!isset($this->recData['ui']))
				$this->recData['ui'] = $uiCfg['ndx'];
		}

		$this->openForm ();
			$this->addInput('fullName', 'Jméno uživatele', self::INPUT_STYLE_STRING, 0, 140);
			$this->addInput('email', 'Přihlašovací e-mail', self::INPUT_STYLE_STRING, 0, 70);
			$this->addInputEnum2('ui', ['text' => 'Aplikace', 'class' => ''], $enum, self::INPUT_STYLE_RADIO);
		$this->closeForm ();
	}

	public function sendRequest ()
	{
		$userInfo = [
			'fullName' => trim($this->recData['fullName']),
			'email' => Str::tolower(trim($this->recData['email'])),
			'ui' => $this->recData['ui'],
		];

		$errors = [];
		if ($userInfo['fullName'] === '')
			$errors[] = 'Jméno uživatele není vyplněno';
		if ($userInfo['email'] === '')
			$errors[] = 'E-mail uživatele není vyplněn';

		$exist = $this->app()->db()->query('SELECT * FROM e10_users_users WHERE [login] = %s', $userInfo['email'])->fetch();
		if ($exist)
			$errors[] = 'Uživatel s e-mailem "'.$userInfo['email'].'" již existuje';

		if (count($errors))
		{
			$this->setFlag ('formStyle', 'e10-formStyleSimple');

			$this->openForm (self::ltForm);
				foreach ($errors as $e)
					$this->addStatic(['text' => $e, 'class' => 'padd5 block']);
			$this->closeForm ();
			$this->stepResult['lastStep'] = 1;
			return;
		}


		$tableUsers = new \e10\users\TableUsers($this->app());
		$newUserNdx = $tableUsers->createUser($userInfo);

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'system/iconUser';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přidat nového uživatele'];

		return $hdr;
	}
}

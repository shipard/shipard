<?php

namespace E10\Persons;

use \Shipard\Application\Application, E10\TableForm, E10\Wizard, E10\utils, e10\str, \translation\dicts\e10\base\system\DictSystem;
use \Shipard\Utils\World;
use \Shipard\Utils\Email;
use \e10\base\libs\UtilsBase;

CONST rqtUserSelfRegistration = 0, rqtLostPassword = 1, rqtFirstLogin = 2, rqtInvitationRequest = 3, rqtActivateShipardAccount = 4;


function createNewPerson (\Shipard\Application\Application $app, $personData)
{
	/** @var \e10\persons\TablePersons $tablePersons */
	$tablePersons = $app->table('e10.persons.persons');
	$newPerson = [];

	$personHead = $personData ['person'];
	utils::addToArray ($newPerson, $personHead, 'firstName');
	utils::addToArray ($newPerson, $personHead, 'lastName');
	utils::addToArray ($newPerson, $personHead, 'fullName', '');
	utils::addToArray ($newPerson, $personHead, 'company', 0);
	utils::addToArray ($newPerson, $personHead, 'accountType', 0);

	$newPerson ['personType'] = 1;

	if ($newPerson ['company'] == 0)
		$newPerson ['fullName'] = $newPerson ['lastName'].' '.$newPerson ['firstName'];
	else {
		$newPerson ['lastName'] = $newPerson ['fullName'];
		$newPerson ['personType'] = 2;
	}

	if (isset ($personHead ['roles']))
		$newPerson ['roles'] = $personHead ['roles'];

	if (isset ($personHead ['login']))
	{
		$newPerson ['login'] = $personHead ['login'];
		$newPerson ['loginHash'] = md5(strtolower(trim($personHead ['login'])));
		$newPerson ['accountState'] = 1;
	}

	utils::addToArray ($newPerson, $personHead, 'docState', 4000);
	utils::addToArray ($newPerson, $personHead, 'docStateMain', 2);

	$newPersonNdx = $tablePersons->dbInsertRec($newPerson);

	// -- contactInfo
	if (isset($personData ['contacts']))
	{
		forEach ($personData ['contacts'] as $contact)
		{
			$newContact = array ('property' => $contact ['type'], 'group' => 'contacts', 'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
													'valueString' => $contact ['value'], 'created' => new \DateTime ());
			$app->db->query ("INSERT INTO [e10_base_properties]", $newContact);
		}
	}

	// -- address
	if (isset ($personData ['address']))
	{
		forEach ($personData ['address'] as $address)
		{
			$newAddress = array ('tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx);
			utils::addToArray ($newAddress, $address, 'specification', '');
			utils::addToArray ($newAddress, $address, 'street', '');
			utils::addToArray ($newAddress, $address, 'city', '');
			utils::addToArray ($newAddress, $address, 'zipcode', '');
			utils::addToArray ($newAddress, $address, 'worldCountry', World::countryNdx($app, $app->cfgItem ('options.core.ownerDomicile', 'cz')));
			utils::addToArray ($newAddress, $address, 'country', $app->cfgItem ('options.core.ownerDomicile', 'cz'));
			$app->db->query ("INSERT INTO [e10_persons_address]", $newAddress);
		}
	}

	// -- identification
	if (isset ($personData ['ids']))
	{
		forEach ($personData ['ids'] as $id)
		{
			$newId = array ('property' => $id ['type'], 'group' => 'ids', 'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
											'valueString' => $id ['value'], 'created' => new \DateTime ());
			if ($id ['type'] === 'birthdate')
			{
				$newId['valueDate'] = $id ['value'];
			}
			$app->db->query ("INSERT INTO [e10_base_properties]", $newId);
		}
	}

	// -- groups
	if (isset ($personData ['groups']))
	{
		forEach ($personData ['groups'] as $gid)
		{
			$gidNdx = $gid;
			if ($gid[0] === '@')
			{
				$gndx = $app->db()->query ('SELECT * FROM e10_persons_groups WHERE systemGroup = %s', substr($gid, 1))->fetch();
				if ($gndx)
					$gidNdx = $gndx['ndx'];
			}

			$app->db()->query ('INSERT INTO [e10_persons_personsgroups] ([person], [group]) VALUES (%i, %i)', $newPersonNdx, $gidNdx);
		}
	}

	// -- paymentInfo
	if (isset($personData ['payments']))
	{
		forEach ($personData ['payments'] as $contact)
		{
			$newContact = array ('property' => $contact ['type'], 'group' => 'payments', 'tableid' => 'e10.persons.persons', 'recid' => $newPersonNdx,
				'valueString' => $contact ['value'], 'created' => new \DateTime ());
			$app->db->query ("INSERT INTO [e10_base_properties]", $newContact);
		}
	}

	// -- password
	if (isset ($personData['password']))
	{
		$newPassword = [
			'person' => $newPersonNdx, 'emailHash' => $newPerson ['loginHash'],
			'salt' => $personData['password']['salt'], 'password' => $personData['password']['password'], 'pwType' => 0, 'version' => 1,
		];
		$app->db()->query ("INSERT INTO [e10_persons_userspasswords]", $newPassword);
	}

	$personRecData = $tablePersons->loadItem($newPersonNdx);
	$tablePersons->checkAfterSave2 ($personRecData);
	$tablePersons->docsLog($newPersonNdx);

	return $newPersonNdx;
} // createNewPerson


/**
 * LoginForm
 *
 */

class LoginForm extends \Shipard\Base\WebForm
{
	public $authenticator;

	public function __construct ($authenticator)
	{
		parent::__construct ($authenticator->app);
		$this->authenticator = $authenticator;
	}

	public function createFormCode ()
	{
		$referer = $this->authenticator->loginReferer ();

		$userLoginAutoComplete = " autocomplete='off'";

		if ($this->authenticator->option ('enableLoginAutocomplete', 0))
			$userLoginAutoComplete = '';

		$c = '';

		$c .= "<form class='form-horizontal' method='POST' action='{$this->app->dsRoot}/" . $this->authenticator->option ('pathBase') . "/" . $this->authenticator->option ('pathLoginCheck') . "' id='e10-lf'$userLoginAutoComplete>";

		if ($this->app->testPostParam ("from", NULL) != NULL)
			$c .= "<div class='alert alert-danger'>".DictSystem::es(DictSystem::diLoginForm_Error_WrongLoginOrPassword)."</div>";

		$c .= $this->addInputBox (DictSystem::text(DictSystem::diCore_Email), 'email', 'login', ['fullWidth' => 1]);
		$c .= $this->addInputBox (DictSystem::text(DictSystem::diCore_Password), 'password', 'password', ['fullWidth' => 1]);

		//if ($this->authenticator->option ('enableLoginRemember', 0))
		//	$c .= $this->addFormInput (DictSystem::text(DictSystem::diLoginForm_RememberMe), 'checkbox', 'loginRemember');

		$c .= "<input type='hidden' name='from' value='$referer'>";

		$c .= "<div class='form-group'>";
		$c .= "<div class='col-sm-offset-2 col-sm-10'>";
		$c .= "<button type='submit' class='btn btn-primary' name='doit'>".DictSystem::es(DictSystem::diLoginForm_LoginButton)."</button>";

		//$c .= " &nbsp; <a href='".$this->app->urlRoot  . "/" . $this->authenticator->option ('pathBase') . "/" . $this->authenticator->option ('pathLostPassword')."'>Nezn??m heslo</a>";
		//if ($this->app->cfgItem ('enableUserRegistration', 0))
		//	$c .= " | <a  href='".$this->app->dsRoot  . "/" . $this->authenticator->option ('pathBase') . "/" . $this->authenticator->option ('pathRegistration')."'>Chci se zaregistrovat</a>";
		$c .= '</div>';
		$c .= '</div>';

		$c .= "</fieldset></form>";


		return $c;
	}
} // LoginForm


/**
 * RegistrationForm
 *
 */

class RegistrationForm extends \Shipard\Base\WebForm
{
	public function createFormCode ()
	{
		$useReCaptcha = $this->app->cfgItem('recaptcha-v3-app-site-key', '') !== '';


		$c = "<form class='form-horizontal' method='POST'>";
		$c .= "<input type='hidden' name='webFormId' value='e10pro.hosting.server.userRegForm'/>";
		if ($useReCaptcha)
		{
			$c .= "<input type='hidden' id='recaptcha-response' name='webFormReCaptchtaResponse' value=''/>";
		}
	
		$c .= "<fieldset>";

		$c .= $this->addInputBox ('Jm??no a p????jmen??', 'text', 'regName');
		$c .= $this->addInputBox ('E-mail', 'email', 'regEmail'/*, array ('help' => 'Funk??n?? e-mail je nezbytn?? k aktivaci registrace')*/);
		$c .= $this->addInputBox ('Heslo', 'password', 'regPassword');
		$c .= $this->addInputBox ('Potvrzen?? hesla', 'password', 'regPassword2');

		$c .= "</fieldset>";
		$c .= "<div class='form-actions'><button type='submit' class='btn btn-primary'>Zaregistrovat</button></div>";
		$c .= '</form>';

		return $c;
	}

	public function fields ()
	{
		return array ('regFormModuleId', 'regName', 'regEmail', 'regPassword');
	}

	public function emailExist ()
	{
		$emailHash = md5(strtolower(trim($this->app->testPostParam ("regEmail"))));

		$e = $this->app->db()->query ("SELECT * FROM [e10_persons_persons] WHERE [loginHash] = %s", $emailHash)->fetch ();
		if ($e)
			return TRUE;
		return FALSE;
	}

	public function validate ()
	{
		if ($this->app->testPostParam ("regName") == "")
		{
			$this->formErrors ['regName'] = 'Jm??no nen?? vypln??no';
			return FALSE;
		}

		if ($this->app->testPostParam ("regEmail") == "")
		{
			$this->formErrors ['regEmail'] = 'Email nen?? vypln??n';
			return FALSE;
		}

		if (!Email::validateEmailAdress ($this->app->testPostParam ("regEmail")))
		{
			$this->formErrors ['regEmail'] = 'Zadejte pros??m platnou emailovou adresu';
			return FALSE;
		}

		if ($this->emailExist ())
		{
			$this->formErrors ['regEmail'] = 'Tento e-mail je ji?? zaregistrov??n';
			return FALSE;
		}

		if (str::strlen ($this->app->testPostParam ("regPassword")) < 7)
		{
			$this->formErrors ['regPassword'] = 'Heslo mus?? m??t alespo?? 7 znak??';
			return FALSE;
		}

		if ($this->app->testPostParam ("regPassword") == "")
		{
			$this->formErrors ['regPassword'] = 'Heslo nen?? vypln??no';
			return FALSE;
		}

		if ($this->app->testPostParam ("regPassword") != $this->app->testPostParam ("regPassword2"))
		{
			$this->formErrors ['regPassword2'] = 'Hesla nejsou stejn??';
			return FALSE;
		}

		$reCaptchaResponse = $this->app->testPostParam ('webFormReCaptchtaResponse', NULL);
		if ($reCaptchaResponse !== NULL)
		{
			if ($reCaptchaResponse === '')
			{
				$this->formErrors ['msg'] = 'Odesl??n?? formul????e se nezda??ilo.';
				return FALSE;
			}

			$validateUrl = 'https://www.google.com/recaptcha/api/siteverify?secret='.$this->app->cfgItem('recaptcha-v3-app-secret-key', '').'&response='.$reCaptchaResponse.'&remoteip='.$_SERVER ['REMOTE_ADDR'];
			$validateResult =  \E10\http_post ($validateUrl, '');
			$validateResultData = json_decode($validateResult['content'], TRUE);
			if ($validateResultData && isset($validateResultData['success']))
			{
				if ($validateResultData['success'])
				{
					if ($validateResultData['score'] < 0.5)
					{
						$this->formErrors ['msg'] = 'Va??e registrace bohu??el vypad?? jako SPAM.';
						return FALSE;
					}
					//$this->spamScore = strval($validateResultData['score']);
				}
				else
				{
					$this->formErrors ['msg'] = 'Odesl??n?? formul????e se nezda??ilo.';
					return FALSE;
				}
			}
		}

		return TRUE;
	}
} // RegistrationForm


/**
 * LostPasswordForm
 *
 */

class LostPasswordForm extends \Shipard\Base\WebForm
{
	var $accountType;
	public function createFormCode ()
	{
		$c = "<form class='form-horizontal' method='POST'>
					<input type='hidden' name='webFormId' value='e10pro.hosting.server.lastPasswordForm'/>";

		$c .= "<p>".DictSystem::es(DictSystem::diLostPasswordForm_InfoText).'</p>';
		$c .= $this->addInputBox ('E-mail', 'email', 'regEmail');

		$c .= "<div class='form-group'>
						<div class='col-sm-12'>
						<button type='submit' class='btn btn-primary'>".DictSystem::es(DictSystem::diLostPasswordForm_SendButton)."</button></div></div>";
		$c .= '</form>';

		return $c;
	}

	public function fields ()
	{
		return array ('regFormModuleId', 'regEmail');
	}

	public function emailExist ()
	{
		$this->accountType = -1;
		$emailHash = md5(strtolower(trim($this->app->testPostParam ("regEmail"))));

		$e = $this->app->db()->query ("SELECT * FROM [e10_persons_persons] WHERE [loginHash] = %s", $emailHash)->fetch ();
		if ($e)
		{
			$this->accountType = $e['accountType'];
			return TRUE;
		}
		return FALSE;
	}

	public function validate ()
	{
		if ($this->app->testPostParam ("regEmail") == "")
		{
			$this->formErrors ['regEmail'] = DictSystem::text(DictSystem::diLostPasswordForm_Error_BlankEmail);
			return FALSE;
		}

		if (!Email::validateEmailAdress ($this->app->testPostParam ("regEmail")))
		{
			$this->formErrors ['regEmail'] = DictSystem::text(DictSystem::diLostPasswordForm_Error_InvalidEmail);
			return FALSE;
		}

		if (!$this->emailExist ())
		{
			$this->formErrors ['regEmail'] = DictSystem::text(DictSystem::diLostPasswordForm_Error_UnknownEmail);
			return FALSE;
		}

		return TRUE;
	}
} // LostPasswordForm

/**
 * ActivateAccountForm
 *
 */

class ActivateAccountForm extends \Shipard\Base\WebForm
{
	public function createFormCode ()
	{
		$c = "
	<form class='form-horizontal' method='POST'>
	<input type='hidden' name='webFormId' value='e10pro.hosting.server.userRegForm'/>
  <fieldset>
    <legend>". utils::es ($this->formTitle()) . '</legend>';

		$c .= $this->addFormInput ('Heslo', 'password', 'regPassword');
		$c .= $this->addFormInput ('Potvrzen?? hesla', 'password', 'regPassword2');

		$c .= "</fieldset>";
		$c .= "<div class='form-actions'><button type='submit' class='btn btn-primary'>Odeslat</button></div>";
		$c .= '</form>';

		return $c;
	}

	public function fields ()
	{
		return array ('regPassword');
	}

	public function validate ()
	{
		if ($this->app->testPostParam ("regPassword") == "")
		{
			$this->formErrors ['regPassword'] = 'Heslo nen?? vypln??no';
			return FALSE;
		}

		if (str::strlen ($this->app->testPostParam ("regPassword")) < 7)
		{
			$this->formErrors ['regPassword'] = 'Heslo mus?? m??t alespo?? 7 znak??';
			return FALSE;
		}

		if ($this->app->testPostParam ("regPassword") != $this->app->testPostParam ("regPassword2"))
		{
			$this->formErrors ['regPassword2'] = 'Hesla nejsou stejn??';
			return FALSE;
		}

		return TRUE;
	}

	public function formTitle ()
	{
		return 'Prvn?? p??ihl????en??';
	}
} // ActivateAccountForm


/**
 * LostPasswordForm
 *
 */

class SetLostPasswordForm extends ActivateAccountForm
{
	public function formTitle ()
	{
		return 'Vytvo??en?? nov??ho hesla';
	}
}

/**
 * Authenticator
 *
 */

class Authenticator extends \Shipard\Application\Authenticator
{
	function checkPassword($userPassword, $pwdInfo)
	{
		if ($pwdInfo['version'] == 0)
		{
			$passwordHash = sha1($userPassword . $pwdInfo ['salt']);
			if ($passwordHash === $pwdInfo['password'])
			{
				$newPassword = password_hash($userPassword, PASSWORD_BCRYPT, ['cost' => 12]);
				$this->app->db()->query('UPDATE [e10_persons_userspasswords] SET [version] = %i', 1, ', [password] = %s', $newPassword, ' WHERE [ndx] = %i', $pwdInfo['ndx']);
				return TRUE;
			}
		}
		elseif ($pwdInfo['version'] == 1)
		{
			if (password_verify($userPassword, $pwdInfo['password']))
				return TRUE;
		}
		return FALSE;
	}

	public function activateAccount($person)
	{
		if ($person['personType'] == 3)
			return $this->activateRobotsAccount($person);

		$dact = $this->app->cfgServer['useHosting'] ? Authenticator::dactShipard : Authenticator::dactLocal;

		if ($dact == Authenticator::dactShipard)
		{
			$person ['accountType'] = Authenticator::actShipard;
			$person ['accountState'] = Authenticator::acsActive;
			$this->app->db()->query("UPDATE [e10_persons_persons] SET [accountType] = %i, [accountState] = %i WHERE [ndx] = %i",
					Authenticator::actShipard, Authenticator::acsActive, $person ['ndx']);

			$url = $this->app->cfgItem('authServerUrl') . 'user/checkuserregistration';

			$request = array('newUser' => $person, 'dsid' => $this->app->cfgItem('dsid'));
			$response = \E10\http_post($url, json_encode($request));
			$responseData = json_decode($response['content'], TRUE);
		} else
			if ($dact == Authenticator::dactLocal)
			{
				$person ['accountType'] = Authenticator::actLocal;
				$person ['accountState'] = Authenticator::acsActive;
				$this->app->db()->query("UPDATE [e10_persons_persons] SET [accountType] = %i, [accountState] = %i WHERE [ndx] = %i",
						Authenticator::actLocal, Authenticator::acsActive, $person ['ndx']);

				$this->createNewRequest(rqtFirstLogin, $person, $person['loginHash']);
			}

		return TRUE;
	}

	public function activateRobotsAccount($person)
	{
		$person ['accountType'] = Authenticator::actLocal;
		$person ['accountState'] = Authenticator::acsActive;

		// -- apiKey
		$loginHash = $person['loginHash'];

		$apiKeySrc = '';
		for ($step = 0; $step < mt_rand(10, 50); $step++)
			$apiKeySrc = base_convert(mt_rand(1000000, 9999999), 10, 35) . base_convert(mt_rand(1000000, 9999999), 10, 35);

		$newPwd = base_convert(mt_rand(1000000, 9999999), 10, 35) . base_convert(mt_rand(1000000, 9999999), 10, 35);
		$salt = sha1('--' . time() . "--" . $person['fullName'] . '--' . mt_rand() . $loginHash);
		$password = sha1($newPwd . $salt);

		$apiKey = ['person' => $person['ndx'], 'salt' => $salt, 'password' => $password, 'emailHash' => sha1($apiKeySrc), 'pwType' => 1];
		$this->app->db()->query('INSERT INTO [e10_persons_userspasswords]', $apiKey);

		// -- activate
		$this->app->db()->query("UPDATE [e10_persons_persons] SET [accountType] = %i, [accountState] = %i WHERE [ndx] = %i",
				Authenticator::actLocal, Authenticator::acsActive, $person ['ndx']);

		return TRUE;
	}

	function addToLog ($info)
	{
		$item = $info;

		$ipAddr = (isset($_SERVER ['REMOTE_ADDR'])) ? $_SERVER ['REMOTE_ADDR'] : '0.0.0.0';

		$item ['created'] = new \DateTime();
		$item ['ipaddress'] = $ipAddr;

		$this->app->db()->query ('INSERT INTO [e10_base_authLog] ', $item);
	}

	public function authenticateUser(\Shipard\Application\Application $app, array &$credentials)
	{
		$email = $credentials['login'];
		$emailHash = md5(strtolower(trim($email)));

		$row = $app->db->fetch(
				'SELECT [ndx], [fullName], [firstName], [lastName], [accountType], [roles] FROM [e10_persons_persons] ',
				'WHERE [loginHash] = %s', $emailHash, ' AND [docState] IN %in', [4000, 8000]
		);
		if (!$row)
		{
			$this->addToLog(['eventType' => 5, 'login' => $credentials['login']]);
			return FALSE;
		}

		if (isset ($credentials['pin']) && $credentials['pin'] !== '')
		{
			$pw = $this->app->db()->query('SELECT * FROM [e10_persons_userspasswords] WHERE [pwType] = 2 AND [person] = %i', $row['ndx'])->fetch();
			if ($pw)
			{
				if (!$this->checkPassword($credentials['pin'], $pw))
					return FALSE;
			} else
				return FALSE;
			$this->startSession($this->app, $row['ndx'], '');
		}
		else
		if ($row['accountType'] == Authenticator::actLocal)
		{
			$pw = $this->app->db()->query("SELECT * FROM [e10_persons_userspasswords] WHERE [pwType] = 0 AND [person] = %i", $row['ndx'])->fetch();
			if ($pw)
			{
				if (!$this->checkPassword($credentials['password'], $pw))
				{
					$this->addToLog(['eventType' => 2, 'login' => $credentials['login']]);
					return FALSE;
				}
			}
			else
			{
				$this->addToLog(['eventType' => 2, 'login' => $credentials['login']]);
				return FALSE;
			}

			if ($this->app->deviceId === '')
			{
				$this->app->deviceId = utils::createToken (40);
				$this->app->setCookie('_shp_did', $this->app->deviceId, time() + 10 * 365 * 86400);
			}

			$this->startSession($this->app, $row['ndx'], '');
		}
		else
		if ($row['accountType'] == Authenticator::actShipard)
		{
			$opts = [
					'http' => [
							'timeout' => 30,
							'method' => "GET",
							'header' => "e10-login-user: " . base64_encode($credentials['login']) . "\r\n" .
									"e10-login-pw: " . base64_encode($credentials['password']) . "\r\n" .
									"e10-login-sid: " . base64_encode($this->app->sessionId) . "\r\n" .
									"e10-remote-ipaddr: " . base64_encode($_SERVER ['REMOTE_ADDR']) . "\r\n" .
									"e10-remote-dsgid: " . base64_encode($this->app->cfgItem('dsid')) . "\r\n" .
									"e10-device-id: " . base64_encode($this->app->deviceId) . "\r\n" .
									"e10-device-info: " . base64_encode(json_encode($this->app->deviceInfo)) . "\r\n" .
									"e10-client-type: " . base64_encode(implode('.', $this->app->clientType)) . "\r\n" .
									"Connection: close\r\n"
					]
			];
			$context = stream_context_create($opts);

			$url = $app->cfgItem('authServerUrl') . "/users.php?op=checkLogin";
			$resultCode = file_get_contents($url, false, $context);
			if ($resultCode === FALSE)
			{
				$this->addToLog(['eventType' => 6, 'login' => $credentials['login']]);
				return FALSE;
			}

			$resultData = json_decode($resultCode, true);
			if (!isset ($resultData ['data']['success']) || $resultData ['data']['success'] !== 1)
			{
				$this->addToLog(['eventType' => 2, 'login' => $credentials['login']]);
				return FALSE;
			}

			if (isset($resultData['data']['did']) && $resultData['data']['did'] !== '' && $this->app->deviceId === '')
				$this->app->deviceId = $resultData['data']['did'];
			$this->app->setCookie('_shp_did', $this->app->deviceId, time() + 10 * 365 * 86400);
			$this->startSession($this->app, $row ['ndx'], $resultData['data']['sid']);
		}
		else
		{
			$this->addToLog(['eventType' => 2, 'login' => $credentials['login']]);
			return FALSE;
		}

		$credentials ['userid'] = $row ['ndx'];
		$userRoles = explode('.', $row ['roles']);
		$this->checkRolesDependencies($userRoles, $app->cfgItem('e10.persons.roles'));
		$app->user()->setData(['id' => $row ['ndx'], 'login' => $email, 'name' => $row ['fullName'], 'firstName' => $row ['firstName'], 'lastName' => $row ['lastName'], 'roles' => $userRoles]);

		// -- TODO: temporary
		if ($this->app->testCookie ('_shp_did') === '')
			$this->app->setCookie('_shp_did', $this->app->deviceId, time() + 10 * 365 * 86400);

		$this->addToLog(['eventType' => 1, 'user' => $row ['ndx'], 'session' => $this->app->sessionId, 'deviceId' => $this->app->deviceId]);
		$this->app->updateDeviceInfo();

		return TRUE;
	}

	public function authenticateSession(\Shipard\Application\Application $app, $sessionId)
	{
		$updateDeviceInfo = FALSE;
		$row = $app->db->fetch('SELECT [person] FROM [e10_persons_sessions] WHERE [ndx] = %s', $sessionId);
		if ($row)
		{
			$user = $app->db->fetch(
					'SELECT [ndx], [firstName], [lastName], [fullName], [roles], [login] FROM [e10_persons_persons] ',
					'WHERE [ndx] = %i', $row ['person'], ' AND [docState] IN %in', [4000, 8000]
			);
			if (!$user)
			{
				$this->addToLog(['eventType' => 4, 'session' => $sessionId, 'login' => '#'.$row ['person']]);
				return FALSE;
			}
		}
		else
		{
			$opts = [
					'http' => [
							'timeout' => 30,
							'method' => "GET",
							'header' =>
									"e10-login-sid: " . base64_encode($this->app->sessionId) . "\r\n" .
									"e10-remote-ipaddr: " . base64_encode($_SERVER ['REMOTE_ADDR']) . "\r\n" .
									"e10-remote-dsgid: " . base64_encode($this->app->cfgItem('dsid')) . "\r\n" .
									"e10-device-id: " . base64_encode($this->app->deviceId) . "\r\n" .
									"e10-device-info: " . base64_encode(json_encode($this->app->deviceInfo)) . "\r\n" .
									"e10-client-type: " . base64_encode(implode('.', $this->app->clientType)) . "\r\n" .
									"Connection: close\r\n"
					]
			];
			$context = stream_context_create($opts);

			$url = $app->cfgItem('authServerUrl') . "/users.php?op=checkLogin";
			$resultCode = file_get_contents($url, false, $context);
			$resultData = json_decode($resultCode, true);

			if (!$resultData || !isset($resultData['data']['success']) || !$resultData['data']['success'])
			{
				error_log ("### authenticateSession: Remote session authorization from `$url` failed: ".json_encode($resultCode));
				return FALSE;
			}
			$user = $app->db->fetch(
					'SELECT [ndx], [firstName], [lastName], [fullName], [roles], [login] FROM [e10_persons_persons] ',
					'WHERE [loginHash] = %s', $resultData['data']['user'], ' AND [docState] IN %in', [4000, 8000]
			);

			if (!$user)
			{
				$this->addToLog(['eventType' => 4, 'session' => $sessionId, 'login' => '@'.$resultData['data']['user']]);
				return FALSE;
			}

			if (isset($resultData['data']['did']) && $resultData['data']['did'] !== '' && $this->app->deviceId === '')
				$this->app->deviceId = $resultData['data']['did'];
			$this->app->setCookie('_shp_did', $this->app->deviceId, time() + 10 * 365 * 86400);

			$this->addToLog(['eventType' => 3, 'user' => $user['ndx'], 'session' => $resultData['data']['sid'], 'deviceId' => $this->app->deviceId]);
			$this->startSession($this->app, $user['ndx'], $resultData['data']['sid']);
			$updateDeviceInfo = TRUE;
		}

		$userRoles = explode('.', $user ['roles']);
		$this->checkRolesDependencies($userRoles, $app->cfgItem('e10.persons.roles'));
		$app->user()->setData(['id' => $user ['ndx'], 'ndx' => $user ['ndx'], 'login' => $user['login'], 'name' => $user ['fullName'], 'firstName' => $user ['firstName'], 'lastName' => $user ['lastName'], 'roles' => $userRoles]);

		// -- TODO: temporary
		if ($this->app->testCookie ('_shp_did') === '')
			$this->app->setCookie('_shp_did', $this->app->deviceId, time() + 10 * 365 * 86400);

		if ($updateDeviceInfo)
			$this->app->updateDeviceInfo();

		return TRUE;
	}

	function authenticateApiKey(\Shipard\Application\Application $app, $apiKey)
	{
		$row = $this->app->db()->query("SELECT * FROM [e10_persons_userspasswords] WHERE [pwType] = 1 AND [emailHash] = %s", $apiKey)->fetch();
		if (!$row)
			return FALSE;

		$user = $app->db->fetch(
				'SELECT [ndx], [fullName], [firstName], [lastName], [roles], [login] FROM [e10_persons_persons] ',
				'WHERE [ndx] = %i', $row ['person'], ' AND [docState] IN %in', [4000, 8000]
		);
		if (!$user)
			return FALSE;

		$userRoles = explode('.', $user ['roles']);
		$this->checkRolesDependencies($userRoles, $app->cfgItem('e10.persons.roles'));
		$app->user()->setData(['id' => $user ['ndx'], 'login' => $user['login'], 'name' => $user ['fullName'], 'firstName' => $user ['firstName'], 'lastName' => $user ['lastName'], 'roles' => $userRoles]);

		return TRUE;
	}

	function authenticateRobot(\Shipard\Application\Application $app, $apiKey)
	{
		$row = $this->app->db()->query("SELECT * FROM [e10_persons_userspasswords] WHERE [pwType] = 1 AND [emailHash] = %s", $apiKey)->fetch();
		if (!$row)
			return FALSE;

		$user = $app->db->fetch(
			'SELECT [ndx], [fullName], [firstName], [lastName], [roles], [login] FROM [e10_persons_persons] ',
			'WHERE [ndx] = %i', $row ['person'], ' AND [docState] IN %in', [4000, 8000]
		);
		if (!$user)
			return FALSE;

		$userRoles = explode('.', $user ['roles']);
		$this->checkRolesDependencies($userRoles, $app->cfgItem('e10.persons.roles'));
		$app->user()->setData(['id' => $user ['ndx'], 'login' => $user['login'], 'name' => $user ['fullName'], 'firstName' => $user ['firstName'], 'lastName' => $user ['lastName'], 'roles' => $userRoles]);

		if ($this->app->deviceId === '')
		{
			$this->app->deviceId = utils::createToken (40);
			$this->app->setCookie('_shp_did', $this->app->deviceId, time() + 10 * 365 * 86400);
		}

		$this->startSession($this->app, $user['ndx'], '');

		return TRUE;
	}

	public function runAsUser($userNdx)
	{
		$user = $this->app->db()->query(
			'SELECT [ndx], [fullName], [firstName], [lastName], [roles], [login] FROM [e10_persons_persons] ',
			'WHERE [ndx] = %i', $userNdx, ' AND [docState] IN %in', [4000, 8000])->fetch();
		if (!$user)
			return FALSE;

		$userRoles = explode('.', $user ['roles']);
		$this->checkRolesDependencies($userRoles, $this->app->cfgItem('e10.persons.roles'));
		$this->app->user()->setData(['id' => $user ['ndx'], 'login' => $user['login'], 'name' => $user ['fullName'], 'firstName' => $user ['firstName'], 'lastName' => $user ['lastName'], 'roles' => $userRoles]);

		return TRUE;
	}

	public function setUser(Application $app, $userNdx)
	{
		$user = $app->db->fetch('SELECT [ndx], [fullName], [firstName], [lastName], [roles], [login] FROM [e10_persons_persons] WHERE [ndx] = %i', $userNdx);
		$userRoles = explode('.', $user ['roles']);
		$this->checkRolesDependencies($userRoles, $app->cfgItem('e10.persons.roles'));
		$app->user()->setData(['id' => $user ['ndx'], 'name' => $user ['fullName'], 'firstName' => $user ['firstName'], 'lastName' => $user ['lastName'], 'roles' => $userRoles]);
		return TRUE;
	}

	function checkUserRegistration()
	{
		$data = array('success' => 0);
		$r = array();
		$r ['objectType'] = 'call';

		$request = json_decode($this->app->testGetData(), TRUE);
		if ($request)
		{
			// -- check data source
			$dsid = $request['dsid'];
			$dataSource = $this->app->db->fetch('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsid);
			if ($dataSource)
			{
				$loginHash = $request['newUser']['loginHash'];

				$existingPerson = $this->app->db->fetch('SELECT * FROM [e10_persons_persons] WHERE [loginHash] = %s', $loginHash);
				if ($existingPerson)
				{ // person exist
					// -- connect to data source
					$userds = $this->app->db->fetch('SELECT * FROM [hosting_core_dsUsers] WHERE [dataSource] = %i AND [user] = %i',
						$dataSource['ndx'], $existingPerson['ndx']);
					if (!$userds)
					{ // connect
						$newLinkedDataSource = ['user' => $existingPerson['ndx'], 'dataSource' => $dataSource['ndx'],
								'created' => new \DateTime(), 'docState' => 4000, 'docStateMain' => 2];
						$tableUsersDS = $this->app->table('hosting.core.dsUsers');
						$tableUsersDS->addUsersDSLink($newLinkedDataSource);
						// -- send email

						$data['success'] = 1;
					}
				} else
				{ // person not found

					// create request
					$newRequest = array('dsid' => $dsid, 'dsName' => $dataSource['name'], 'person' => $request['newUser']);
					$this->createNewRequest(rqtActivateShipardAccount, $newRequest, $loginHash);

					$data['success'] = 1;
				}
			} else
				$data ['message'] = "datasource not found";
		}

		$r ['data'] = $data;

		$page = array();
		$page ['code'] = json_encode($r);

		return $page;
	}


	function createNewRequest($type, $data, $loginHash = '')
	{
		$requestData = json_encode($data);
		$requestId = sha1($requestData . time() . mt_rand(100000, 999999));

		$dateCreated = new \DateTime();
		$dateValid = new \DateTime();
		$dateValid->add(new \DateInterval('P1D'));

		$title = '';
		$emailAddress = '';

		switch ($type)
		{
			case  rqtUserSelfRegistration:
				$title = 'Registrace: ' . $data['person']['lastName'] . ' ' . $data['person']['firstName'];
				$emailAddress = $data['person']['login'];
				break;
			case  rqtActivateShipardAccount:
				$title = 'Aktivace: ' . $data['person']['lastName'] . ' ' . $data['person']['firstName'];
				$emailAddress = $data['person']['login'];
				break;
			case  rqtFirstLogin:
				$title = 'Prvn?? p??ihl????en??: ' . $data['lastName'] . ' ' . $data['firstName'];
				$emailAddress = $data['login'];
				break;
			case  rqtLostPassword:
				$title = 'Ztracen?? heslo: ' . $data['login'];
				$emailAddress = $data['login'];
				break;
		}

		$newRequest = array('requestType' => $type, 'requestId' => $requestId, 'requestData' => $requestData,
				'subject' => $title, 'loginHash' => $loginHash,
				'created' => $dateCreated, 'validTo' => $dateValid,
				'addressCreate' => $_SERVER ['REMOTE_ADDR'],
				'docState' => 1000, 'docStateMain' => 0);
		$this->app->db->query("INSERT INTO [e10_persons_requests]", $newRequest);

		$email = $this->createEmailForRequest($type, $data, $requestId);
		UtilsBase::sendEmail($this->app, $email ['subject'], $email ['message'], $email ['fromEmail'], $emailAddress, $email ['fromName'], '');
	}

	public function createEmailForRequest($type, $data, $requestId)
	{
		$siteName = $this->app->cfgItem('options.core.ownerShortName', '');
		$siteEmail = $this->app->cfgItem('options.core.ownerEmail', '');
		$sitePhone = $this->app->cfgItem('options.core.ownerPhone', '');
		$siteWeb = $this->app->cfgItem('options.core.ownerWeb', '');

		$email = array();
		$email ['fromEmail'] = $siteEmail;
		$email ['fromName'] = 'Technick?? podpora ' . $siteName;

		$urlHost = $_SERVER['HTTP_HOST'];
		if ($urlHost === 'shipard.com')
			$urlHost = 'me.shipard.com';
		elseif ($urlHost === 'shipard.cz')
			$urlHost = 'muj.shipard.cz';
		elseif ($urlHost === 'uctarna.online')
			$urlHost = 'moje.uctarna.online';

		switch ($type)
		{
			case  rqtUserSelfRegistration:
				$email ['subject'] = 'Potvrzen?? registrace - ' . $siteName;
				$email ['message'] = "Dobr?? den, \naby Va??e registrace na $siteName fungovala, klikn??te pros??m na n??sleduj??c?? odkaz:\n" .
						"{$this->app->urlProtocol}{$urlHost}{$this->app->dsRoot}/user/request/$requestId";
				break;
			case  rqtActivateShipardAccount:
				$email ['subject'] = "Potvrzen?? ????tu na " . $data['dsName'];
				$email ['message'] = "Dobr?? den, \naby V???? ????et na {$data['dsName']} fungoval, klikn??te pros??m na n??sleduj??c?? odkaz:\n" .
						"{$this->app->urlProtocol}{$urlHost}{$this->app->dsRoot}/user/request/$requestId";
				break;
			case  rqtFirstLogin:
				$email ['subject'] = "V???? ????et na " . $data['dsName'];
				$email ['message'] = "Dobr?? den, \naby V???? ????et na {$data['dsName']} fungoval, klikn??te pros??m na n??sleduj??c?? odkaz:\n" .
						"{$this->app->urlProtocol}{$urlHost}{$this->app->dsRoot}/user/request/$requestId";
				break;
			case  rqtLostPassword:
				$email ['subject'] = '????dost o nov?? heslo na ' . $siteName;
				$email ['message'] = "Dobr?? den, \npro vytvo??en?? nov??ho hesla na $siteName klikn??te pros??m na n??sleduj??c?? odkaz:\n" .
						"{$this->app->urlProtocol}{$urlHost}{$this->app->dsRoot}/user/request/$requestId";
				break;
		} // switch ($type)

		$email ['message'] .= "\n--\n  email: $siteEmail | hotline: $sitePhone | $siteWeb \n\n";
		return $email;
	}

	public function formCode($formType)
	{
		$this->firstInput = TRUE;

		if ($formType == 'login')
			return $this->formCodeLogin();
		if ($formType == 'logout')
			return $this->formCodeLogout();
		if ($formType == 'registration')
		{
			if ($this->app->cfgItem ('enableUserRegistration', 0))
				return $this->formCodeRegistration();
		}
		if ($formType == 'request')
			return $this->formCodeRequest();
		if ($formType == 'lostPassword')
			return $this->formCodeLostPassword();

		return NULL;
	}

	public function formCodeLogin()
	{
		$form = new LoginForm ($this);
		$c = $form->createFormCode();
		$page = array();
		$page ['text'] = $c;
		$page ['title'] = DictSystem::text(DictSystem::diLoginForm_FormTitle);
		return $page;
	} // formCodeLogin


	public function formCodeLogout()
	{
		$page = array();
		$page ['text'] = "Jste odhl????eni.";
		$page ['title'] = 'Odhl????en??';
		return $page;
	} // formCodeLogout


	public function formCodeLostPassword()
	{
		if ($this->app->testGetParam('lpe') !== '')
		{
			$lpe = $this->app->testGetParam('lpe');
			$loginHash = md5(strtolower(trim($lpe)));
			$request = array('loginHash' => $loginHash, 'login' => $lpe);

			$this->createNewRequest(rqtLostPassword, $request, $loginHash);
			return ['title' => 'OK', 'text' => 'OK'];
		}


		$page = array();
		$page ['title'] = DictSystem::text(DictSystem::diLostPasswordForm_FormTitle);

		$wf = new LostPasswordForm ($this->app);

		// done?
		$done = intval($this->app->testGetParam('hotovo'));
		if ($done === 1)
		{
			$page ['text'] = DictSystem::text(DictSystem::diLostPasswordForm_DoneText);
			return $page;
		}

		if (!$wf->getData())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}
		if (!$wf->validate())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}

		if ($wf->accountType == Authenticator::actShipard)
		{
			$url = $this->app->cfgItem('authServerUrl') . 'user/lost-password?lpe=' . $wf->data ['regEmail'];
			\E10\http_post($url, '');
		} else
			if ($wf->accountType == Authenticator::actLocal)
			{
				$loginHash = md5(strtolower(trim($wf->data ['regEmail'])));
				$request = array('loginHash' => $loginHash, 'login' => $wf->data ['regEmail']);

				$this->createNewRequest(rqtLostPassword, $request, $loginHash);
			}

		header('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $this->app->requestPath() . '?hotovo=1');
		die();
	} // formCodeLostPassword

	public function formCodeRegistration()
	{
		$page = array();
		$page ['title'] = 'Registrace u??ivatele';

		$wf = new RegistrationForm ($this->app);

		// done?
		$done = intval($this->app->testGetParam('hotovo'));
		if ($done === 1)
		{
			$page ['text'] = 'Hotovo. Za chv??li obdr????te e-mail pro potvrzen?? registrace.';
			return $page;
		}

		if (!$wf->getData())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}
		if (!$wf->validate())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}

		$names = explode(' ', $wf->data ['regName']);
		$lastName = array_pop($names);
		$firstName = implode(' ', $names);
		$roles = 'user';
		$newPerson ['person'] = array('firstName' => $firstName, 'lastName' => $lastName,
				'roles' => $roles, 'login' => $wf->data ['regEmail'],
				'accountType' => 1);

		$newPerson ['contacts'][] = array('type' => 'email', 'value' => $wf->data ['regEmail']);

		$loginHash = md5(strtolower(trim($wf->data ['regEmail'])));
		$salt = sha1('--' . time() . "--" . $wf->data ['regName'] . '--' . mt_rand() . $loginHash);
		$password = $this->passwordHash($wf->data ['regPassword']);
		$newPerson ['password'] = array('password' => $password, 'salt' => $salt);

		$this->createNewRequest(rqtUserSelfRegistration, $newPerson, $loginHash);

		header('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $this->app->requestPath() . '?hotovo=1');
		die();
	} // formCodeRegistration


	public function formCodeRequest()
	{
		$page = array();
		$requestId = $this->app->requestPath(2);

		// -- load request
		$r = $this->app->db()->query("SELECT * FROM [e10_persons_requests] WHERE [requestId] = %s", $requestId)->fetch();
		if (!$r)
		{
			$page ['title'] = 'Po??adavek neexistuje';
			$page ['text'] = "Po??adavek neexistuje - zkontrolujte odkaz, na kter?? jste kliknuli...";
			return $page;
		}

		if ($r ['docState'] != 1000)
		{
			$page ['title'] = 'Neplatn?? po??adavek';
			$page ['text'] = "Po??adavek je neplatn??. Prove??te akci znovu nebo kontaktujte technickou podporu.";
			return $page;
		}

		switch ($r ['requestType'])
		{
			case  rqtUserSelfRegistration:
				return $this->formCodeRequest_Registration($r);
			case  rqtActivateShipardAccount:
				return $this->formCodeRequest_ActivateShipardAccount($r);
			case  rqtFirstLogin:
				return $this->formCodeRequest_FirstLogin($r);
			case  rqtLostPassword:
				return $this->formCodeRequest_LostPassword($r);
		}

		$page ['title'] = 'Neo??ek??van?? chyba';
		$page ['text'] = 'Do??lo k neo??ek??van?? chyb??.';

		return $page;
	}


	function formCodeRequest_Registration($request)
	{
		$requestData = json_decode($request['requestData'], TRUE);

		\E10\Persons\createNewPerson($this->app, $requestData);

		// set request as confirmed
		$this->app->db()->query("UPDATE [e10_persons_requests] SET [docState] = 4000, [docStateMain] = 2, [finished] = NOW(), [addressConfirm] = %s WHERE [ndx] = %i",
				$_SERVER['REMOTE_ADDR'], $request ['ndx']);

		$page = array();
		$page ['title'] = 'Registrace je dokon??ena';
		$page ['text'] = 'Nyn?? se m????ete p??ihl??sit';

		return $page;
	}


	function formCodeRequest_ActivateShipardAccount($request)
	{
		$requestData = json_decode($request['requestData'], TRUE);

		$wf = new ActivateAccountForm ($this->app);

		// done?
		$done = intval($this->app->testGetParam('done'));
		if ($done === 1)
		{
			$dsid = $requestData['dsid'];
			$dataSource = $this->app->db->fetch('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsid);

			// set request as confirmed
			$this->app->db()->query("UPDATE [e10_persons_requests] SET [docState] = 4000, [docStateMain] = 2, [finished] = NOW(), [addressConfirm] = %s WHERE [ndx] = %i",
					$_SERVER['REMOTE_ADDR'], $request ['ndx']);

			$page ['text'] = "Hotovo. Pokra??ujte na <a href='{$dataSource['urlApp']}app'>" . utils::es($requestData['dsName']) . '</a>.';
			return $page;
		}

		if (!$wf->getData())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}
		if (!$wf->validate())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}

		$newPerson ['person'] = $requestData ['person'];
		$newPerson ['person']['accountType'] = Authenticator::actLocal;
		$newPerson ['person']['accountState'] = Authenticator::acsActive;
		$newPerson ['person']['roles'] = 'user';

		$newPerson ['contacts'][] = array('type' => 'email', 'value' => $newPerson ['person']['login']);

		$loginHash = md5(strtolower(trim($newPerson ['person']['login'])));
		$salt = sha1('--' . time() . "--" . $newPerson ['person']['fullName'] . '--' . mt_rand() . $loginHash);
		$password = $this->passwordHash($wf->data ['regPassword']);
		$newPerson ['password'] = array('password' => $password, 'salt' => $salt);

		$newPersonNdx = \E10\Persons\createNewPerson($this->app, $newPerson);

		$dsid = $requestData['dsid'];
		$dataSource = $this->app->db->fetch('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsid);

		$newLinkedDataSource = ['user' => $newPersonNdx, 'dataSource' => $dataSource['ndx'],
				'created' => new \DateTime(), 'docState' => 4000, 'docStateMain' => 2];
		$tableUsersDS = $this->app->table('hosting.core.dsUsers');
		$tableUsersDS->addUsersDSLink($newLinkedDataSource);

		header('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $this->app->requestPath() . '?done=1');
		die();
	}

	function formCodeRequest_FirstLogin($request)
	{
		$requestData = json_decode($request['requestData'], TRUE);

		$wf = new ActivateAccountForm ($this->app);

		// done?
		$done = intval($this->app->testGetParam('done'));
		if ($done === 1)
		{
			// set request as confirmed
			$this->app->db()->query("UPDATE [e10_persons_requests] SET [docState] = 4000, [docStateMain] = 2, [finished] = NOW(), [addressConfirm] = %s WHERE [ndx] = %i",
					$_SERVER['REMOTE_ADDR'], $request ['ndx']);

			$page ['text'] = "Hotovo. Pokra??ujte <a href='{$this->app->urlRoot}/app'>" . utils::es('p??ihl????en??m do aplikace') . '</a>.';
			return $page;
		}

		if (!$wf->getData())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}
		if (!$wf->validate())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}

		$loginHash = md5(strtolower(trim($requestData['login'])));
		$salt = sha1('--' . time() . "--" . $requestData['fullName'] . '--' . mt_rand() . $loginHash);
		$password = $this->passwordHash($wf->data ['regPassword']);

		$newPassword = [
			'person' => $requestData['ndx'], 'emailHash' => $loginHash,
			'salt' => $salt, 'password' => $password, 'pwType' => 0, 'version' => 1,
		];
		$this->app->db()->query("INSERT INTO [e10_persons_userspasswords]", $newPassword);

		header('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $this->app->requestPath() . '?done=1');
		die();
	}

	function formCodeRequest_LostPassword($request)
	{
		$wf = new SetLostPasswordForm ($this->app);

		// done?
		$done = intval($this->app->testGetParam('done'));
		if ($done === 1)
		{
			// set request as confirmed
			$this->app->db()->query("UPDATE [e10_persons_requests] SET [docState] = 4000, [docStateMain] = 2, [finished] = NOW(), [addressConfirm] = %s WHERE [ndx] = %i",
					$_SERVER['REMOTE_ADDR'], $request ['ndx']);

			$page ['text'] = "Hotovo. Nyn?? se <a href='{$this->app->urlRoot}/user/login'>" . utils::es('m????ete p??ihl??sit') . '</a>.';
			return $page;
		}

		if (!$wf->getData())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}
		if (!$wf->validate())
		{
			$page ['text'] = $wf->createFormCode();
			return $page;
		}

		$loginHash = $request['loginHash'];
		$person = $this->app->db()->query("SELECT * FROM [e10_persons_persons] WHERE [loginHash] = %s", $loginHash)->fetch();

		$this->resetPassword($person, $wf->data ['regPassword']);

		header('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $this->app->requestPath() . '?done=1');
		die();
	}

	public function resetPassword($personRecData, $newPassword)
	{
		if ($newPassword === '')
			return;

		$salt = sha1('--' . time() . "--" . $personRecData ['fullName'] . '--' . mt_rand() . $personRecData ['loginHash']);
		$password = $this->passwordHash($newPassword);

		$newPassword = [
			'emailHash' => $personRecData ['loginHash'],
			'salt' => $salt, 'password' => $password, 'pwType' => 0, 'version' => 1,
		];

		$this->app->db()->query('UPDATE [e10_persons_userspasswords] SET', $newPassword, ' WHERE [pwType] = 0 AND [person] = %i', $personRecData['ndx']);
	}

	function startSession(Application $app, $userNdx, $newSessionId)
	{
		if ($newSessionId === '')
			$newSessionId = $this->newSessionId();
		$newSession = ['ndx' => $newSessionId, 'person' => $userNdx, 'created' => new \DateTime()];
		$app->db->query('INSERT INTO [e10_persons_sessions] ', $newSession);
		$this->app->sessionId = $newSessionId;

		$sessionExpiration = 0;//time()+60*60*24*10;
		if ($this->app->mobileMode)
			$sessionExpiration = time()+60*60*24;

		$this->app->setCookie($this->app->sessionCookieName(), $newSessionId, $sessionExpiration);

		return $newSessionId;
	}

	function newSessionId()
	{
		return utils::createToken (40);
	}

	public function userGroups ($forUserNdx = 0)
	{
		if ($forUserNdx !== 0)
			$userNdx = $forUserNdx;
		else
			$userNdx = $this->app->user()->data ('id');
		$groups = array ();
		$rows = $this->app->db->query ('SELECT * FROM [e10_persons_personsgroups] WHERE [person] = %i', $userNdx);
		foreach ($rows as $r)
			$groups[] = $r['group'];

		return $groups;
	}

	public function userHasRole ($app, $role)
	{
		$userRoles = $app->user()->data ('roles');
		if (($userRoles) && (in_array ($role, $userRoles)))
			return true;
		return false;
	}
} // Authenticator



/**
 * ListAddress
 *
 */


class ListAddress implements \E10\IDocumentList
{
	public $table;
	public $tableAddresses;
	public $listId;
	public $listDefinition;
	public $listGroup = '';
	public $myProperties = array ();
	public $allProperties;
	public $data = array ();
	public $options = 0;

	function init ()
	{
		$this->tableAddresses = $this->table->app()->table('e10.persons.address');
		$this->listDefinition = $this->table->listDefinition ($this->listId);
		if (isset ($this->listDefinition ['group']))
			$this->listGroup = $this->listDefinition ['group'];
	}

	function loadData ()
	{
		$loadedProperties = array ();
		$rowNumber = 0;

		if (isset ($this->recData ['ndx']))
		{
			$sql = "SELECT * FROM [e10_persons_address] where [tableid] = %s AND recid = %i ORDER BY ndx";
			$rows = $this->table->app()->db()->query ($sql, $this->table->tableId (), $this->recData ['ndx']);
			forEach ($rows as $r)
				$this->data [] = $r->toArray();
			if (isset($this->formData))
				$this->formData->lists [$this->listId] = $this->data;
		}
	}

	function saveData ($listData)
	{
		$usedNdx = array ();
		forEach ($listData as &$row)
		{
			$row ['tableid'] = $this->table->tableId();
			$row ['recid'] = $this->recData ['ndx'];

			if (!isset($row['worldCountry']) || intval($row['worldCountry']) === 0)
				$row['worldCountry'] = World::countryNdx($this->table->app(), $this->table->app()->cfgItem ('options.core.ownerDomicile', 'cz'));

			if (!isset($row['docState']) || $row['docState'] === '')
				$row['docState'] = 4000;
			if (!isset($row['docStateMain']) || $row['docStateMain'] === '')
				$row['docStateMain'] = 2;

			if ($row ['ndx'] == 0 || $row ['ndx'] == '')
			{ // insert
				unset ($row['ndx']);
				$this->table->app()->db()->query ("INSERT INTO [e10_persons_address]", $row);
				$newNdx = intval ($this->table->app()->db()->getInsertId ());
				$usedNdx [] = $newNdx;
			}
			else
			{ // update
				$newLocHash = $this->tableAddresses->geoCodeLocHash ($row);
				if ($newLocHash !== $row['locHash'])
				{
					$row['locHash'] = $newLocHash;
					$row['locState'] = 0;
				}
				$this->table->app()->db()->query ("UPDATE [e10_persons_address] SET ", $row, "WHERE [ndx] = %i", $row ['ndx']);
				$usedNdx [] = $row ['ndx'];
			}
		}
		// -- clear deleted rows
		$this->table->app()->db()->query ("DELETE FROM [e10_persons_address] where [tableid] = %s AND [recid] = %i AND [ndx] NOT IN (%sql)",
																								$this->table->tableId(), $this->recData ['ndx'], implode(', ', $usedNdx));
	}

	function setRecord ($listId, \Shipard\Form\TableForm $formData)
	{
		$this->table = $formData->table;
		$this->listId = $listId;
		$this->formData = $formData;
		$this->recData = $formData->recData;
		$this->init ();
	}

	function setRecData ($table, $listId, $recData)
	{
		$this->table = $table;
		$this->listId = $listId;
		$this->recData = $recData;
		$this->init ();
	}

	function createHtmlCode ($options = 0)
	{
		$this->options |= $options;
		$this->loadData ();

		$c = "";

		if ($options & TableForm::loAddToFormLayout == 0)
			$c .= "<div>";

		$rowNumber = 0;
		forEach ($this->data as $row)
		{
			$c .= $this->createHtmlCodeRow ($rowNumber, $row);
			$rowNumber++;
		}

		if ($rowNumber == 0)
		{ // -- no data, open first address
			$c .= $this->createHtmlCodeRow ($rowNumber, NULL);
		}

		if ($options & TableForm::loAddToFormLayout == 0)
			$c .= "</div>";

		return $c;
	}

	function createHtmlCodeRow ($rowNumber, $dataItem)
	{
		$inputPrefix = "lists.{$this->listId}.$rowNumber";

		$rowClass = '';
		if ($this->options & TableForm::loAddToFormLayout)
			$rowClass = " class='e10-flf1'";

		$readOnlyParam = '';
		$disabledParam = '';
		$columnOptions = 0;
		if ($this->formData->readOnly)
		{
			$disabledParam = " disabled='disabled'";
			$readOnlyParam = " readonly='readonly'";
			$columnOptions |= TableForm::coReadOnly;
		}
		$c = "";

		if (!isset($dataItem['docState']) || $dataItem['docState'] === '')
			$dataItem['docState'] = 4000;
		if (!isset($dataItem['docStateMain']) || $dataItem['docStateMain'] === '')
			$dataItem['docStateMain'] = 2;
		if (!isset($dataItem['locHash']))
			$dataItem['locHash'] = '';
		if (!isset($dataItem['ndx']))
			$dataItem['ndx'] = 0;

		if ($this->options & TableForm::loAddToFormLayout == 0)
			$c .= "<table>";

		$c .= "<tr class='e10-flf1 e10-property-group' style='border-top: 1px solid #ddd;'>";

		$c .= "<td class='e10-fl-cellLabel'>";
		$c .= "Adresa";
		$c .= "</td>";

		$c .= "<td class='e10-fl-cellInput'>";
		$types = $this->table->app()->cfgItem ('e10.persons.addressTypes');
		$c .= "<select name='$inputPrefix.type' class='e10-inputEnum' data-fid='{$this->formData->fid}'>";
		foreach ($types as $val => $txt)
			$c .= " <option value='$val'>" . utils::es ($txt['name']) . "</option>";
		$c .= "</select>";
		$c .= "</td>";

		$c .= "</tr>";


		$c .= "<tr$rowClass>";
		$c .= "<td class='e10-fl-cellLabel'>Up??esn??n??</td><td class='e10-fl-cellInput'>" .
					"<input type='hidden' name='$inputPrefix.ndx' value='{$dataItem ['ndx']}' data-fid='{$this->formData->fid}'/>" .
					"<input type='hidden' name='$inputPrefix.docState' class='e10-inputInt' value='{$dataItem ['docState']}' data-fid='{$this->formData->fid}'/>" .
					"<input type='hidden' name='$inputPrefix.docStateMain' class='e10-inputInt' value='{$dataItem ['docStateMain']}' data-fid='{$this->formData->fid}'/>" .
					"<input type='hidden' name='$inputPrefix.locHash' value='{$dataItem ['locHash']}' data-fid='{$this->formData->fid}'/>" .
					"<input type='text' name='$inputPrefix.specification' class='e10-ef-w50' data-fid='{$this->formData->fid}'$readOnlyParam/></td></tr>";
		$c .= "<tr$rowClass><td class='e10-fl-cellLabel'>Ulice</td><td class='e10-fl-cellInput'><input type='text' name='$inputPrefix.street' class='e10-ef-w50' data-fid='{$this->formData->fid}'$readOnlyParam/></td></tr>";
		$c .= "<tr$rowClass><td class='e10-fl-cellLabel'>M??sto</td><td class='e10-fl-cellInput'><input type='text' name='$inputPrefix.city' class='e10-ef-w50' data-fid='{$this->formData->fid}'$readOnlyParam/></td></tr>";
		$c .= "<tr$rowClass><td class='e10-fl-cellLabel'>PS??</td><td class='e10-fl-cellInput'><input type='text' name='$inputPrefix.zipcode' class='e10-ef-w15' data-fid='{$this->formData->fid}'$readOnlyParam/></td></tr>";

		$c .= "<tr$rowClass><td class='e10-fl-cellLabel'>Zem??</td><td class='e10-fl-cellInput'>";

		if (!isset($dataItem['worldCountry']))
			$dataItem['worldCountry'] = World::countryNdx($this->table->app(), $this->table->app()->cfgItem ('options.core.ownerDomicile', 'cz'));

		$tableCountries = $this->table->app()->table('e10.world.countries');
		$ff = new \Shipard\Form\TableForm($this->tableAddresses, '', '');
		$ff->recData = $dataItem;
		$inputC = $tableCountries->columnRefInput ($ff, $this->tableAddresses, 'worldCountry', $columnOptions, 'Zem??', $inputPrefix.'.');
		$c .= $inputC ['inputCode'];

		$c .= "</td>";
		$c .= "</tr>";

		if ($this->options & TableForm::loAddToFormLayout == 0)
			$c .= "</table>";

		return $c;
	}

	function appendRowCode ()
	{
		if (!isset ($this->formData))
			$this->formData->fid = $this->fid;
		$c = "";

		$rowNumber = intval ($this->table->app()->testGetParam ('rowNumber'));
		$dataItem = ['rowNumber' => $rowNumber, 'ndx' => 0, 'street' => '', 'specification' => '', 'value' => '', 'docState' => 4000, 'docStateMain' => 2];

		$this->options |= TableForm::loAddToFormLayout;
		$c .= $this->createHtmlCodeRow ($rowNumber, $dataItem);

		return $c;
	}
}


/* ------
 * Groups
 * ------
 */

function addGroup ($app, $person, $group)
{
	$groupExist = $app->db->fetch ("SELECT [ndx] from [e10_persons_personsgroups] where [person] = %i AND [group] = %i", $person, $group);
	if ($groupExist)
		return 0;

	$app->db->query ("INSERT INTO [e10_persons_personsgroups] ([person], [group]) VALUES (%i, %i)", $person, $group);
	$newNdx = intval ($app->db->getInsertId ());
	return $newNdx;
}

function deleteGroup ($app, $person, $group)
{
	$app->db->query ("DELETE FROM [e10_persons_personsgroups] WHERE [person] = %i AND [group] = %i", $person, $group);
}

function getGroups ($app, $person, $simple = false)
{
	$query = $app->db->query ("SELECT [ndx], [group] from [e10_persons_personsgroups] where [person] = %i", $person);

	$groups = array ();

	if ($simple)
		foreach ($query as $row)
			$groups [] = $row['group'];
	else
		foreach ($query as $row)
			$groups [$row['group']] = $row['ndx'];

	return $groups;
}


class ListGroups implements \E10\IDocumentList
{
	public $formData = NULL;
	public $recData;
	public $table;
	public $listId;
	public $listDefinition;
	public $data = array ();
	public $allTags;

	function init ()
	{
		$this->listDefinition = $this->table->listDefinition ($this->listId);
	}

	function loadData ()
	{
		if (isset($this->recData ['ndx']) && $this->recData ['ndx'])
			$groups = getGroups ($this->table->app(), $this->recData ['ndx'], true);
		else
			$groups = [];
		$this->data = $groups;

		if ($this->formData)
			$this->formData->checkLoadedList ($this);

		if ($this->formData)
			$this->formData->lists [$this->listId] = implode ('.', $this->data);
	}

	function saveData ($listData)
	{
		$currentGroups = getGroups ($this->table->app(), $this->recData ['ndx']);

		if (is_array($listData))
		{
			$newGroups = [];
			foreach ($listData as $og)
			{
				if ($og['group'][0] === '@')
				{
					$gndx = $this->table->db()->query ('SELECT * FROM e10_persons_groups WHERE systemGroup = %s', substr($og['group'], 1))->fetch();
					if ($gndx)
						$newGroups[] = $gndx['ndx'];
				}
				else
					$newGroups[] = $og['group'];
			}
		}
		else
		{
			if ($listData != '')
				$newGroups = explode ('.', $listData);
			else
				$newGroups = array ();
		}

		// new:
		forEach ($newGroups as $g)
		{
			if (!isset ($currentGroups [$g]))
				$this->table->db()->query ("INSERT INTO [e10_persons_personsgroups] ([person], [group]) VALUES (%i, %i)", $this->recData ['ndx'], $g);
		}
		// deleted
		forEach ($currentGroups as $group => $ndx)
		{
			if (!in_array ($group, $newGroups))
				$this->table->db()->query ("DELETE FROM [e10_persons_personsgroups] where [ndx] = %i", $ndx);
		}
	}

	function setRecord ($listId, \Shipard\Form\TableForm $formData)
	{
		$this->listId = $listId;
		$this->formData = $formData;
		$this->recData = $formData->recData;
		$this->table = $formData->table;
		$this->init ();
	}

	function setRecData ($table, $listId, $recData)
	{
		$this->listId = $listId;
		$this->recData = $recData;
		$this->table = $table;
		$this->init ();
	}

	function createHtmlCode ($options = 0)
	{
		$groups = $this->table->app()->cfgItem ('e10.persons.groups', FALSE);
		if (!$groups)
			return NULL;

		$this->loadData ();

		$c = "";
		$class = "class='e10-inputEnum e10-inputEnumMultiple chzn-select'";

		$c .= "<select name='lists.{$this->listId}' id='inp_lists_{$this->listId}' $class multiple='multiple' data-fid='{$this->formData->fid}'>";
		foreach ($groups as $g)
			$c .= " <option value='{$g['id']}'>" . \E10\es ($g['name']) . "</option>";
		$c .= "</select>";

		return $c;
	}
}


/**
 * AddWizard
 *
 */

class AddWizard extends \Shipard\Form\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->recData ['ic'] = trim ($this->recData ['ic']);
			$this->recData ['ic'] = str_replace(' ', '', $this->recData ['ic']);
			$this->aresLoad($this->recData ['ic']);
			if ($this->recData ['state'] === 'ok')
				$this->savePerson();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->stepResult['lastStep'] = 1; $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('ic', 'I??', self::INPUT_STYLE_STRING, 0, 20);
		$this->closeForm ();
	}

	public function aresLoad ($ic)
	{
		define('ARESURL','http://wwwinfo.mfcr.cz/cgi-bin/ares/darv_bas.cgi?ico=');

		$file = @file_get_contents (ARESURL . $ic);

		if ($file)
			$xml = @simplexml_load_string ($file);
		if (isset($xml) && $xml)
		{
			$ns = $xml->getDocNamespaces();
			$data = $xml->children($ns['are']);
			$el = $data->children($ns['D'])->VBAS;
			if (strval($el->ICO) == $ic)
			{
				$this->recData ['ic'] = strval ($el->ICO);
				$this->recData ['dic'] = strval ($el->DIC);
				$this->recData ['fullName'] = strval ($el->OF);

				$street = strval ($el->AA->NU);
				if ($street == '')
					$street = strval ($el->AA->N);
				$this->recData ['street'] = $street . ' ' . strval($el->AA->CD);
				if ($el->AA->CO != '')
					$this->recData ['street'] .= '/' . $el->AA->CO;

				$this->recData ['city']= strval ($el->AA->N);
				$this->recData ['zipcode']= strval ($el->AA->PSC);
				$this->recData ['state'] = 'ok';
				$this->recData ['lastName'] = $this->recData ['fullName'];
			}
			else
			{
				$this->recData ['state'] = 'nonex';
				$this->addMessage("Hledan?? I?? '$ic' nebylo nalezeno.");
			}
		}
		else
		{
			$this->recData ['state'] = 'error';
			$this->addMessage('Informace nelze na????st. Port??l ARES je patrn?? nedostupn??. Zkuste to pros??m pozd??ji.');
		}
	}

	public function savePerson ()
	{
		$newPerson ['person'] = array ();
		$newPerson ['person']['company'] = 1;
		$newPerson ['person']['fullName'] = $this->recData['fullName'];
		$newPerson ['person']['docState'] = 1000;
		$newPerson ['person']['docStateMain'] = 0;

		$newAddress = array ();
		$newAddress ['street'] = $this->recData ['street'];
		$newAddress ['city'] = $this->recData ['city'];
		$newAddress ['zipcode'] = $this->recData ['zipcode'];
		$newAddress ['worldCountry'] = 60; 	// CZ - fixed value
		$newAddress ['country'] = 'cz'; 		// CZ - fixed value
		$newPerson ['address'][] = $newAddress;

		$newPerson ['ids'][] = array ('type' => 'oid', 'value' => $this->recData ['ic']);
		$newPerson ['ids'][] = array ('type' => 'taxid', 'value' => $this->recData ['dic']);

		$newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);

		$this->stepResult ['close'] = 1;
		$this->stepResult ['editDocument'] = 1;
		$this->stepResult ['params'] = ['table' => 'e10.persons.persons', 'pk' => $newPersonNdx];
	}
}


/**
 * Class AddWizardFromID
 * @package E10\Persons
 */
class AddWizardFromID extends Wizard
{
	protected $newPersonNdx = 0;
	protected $tablePersons;

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
		$this->addInput('addToGroups', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
	}

	public function renderFormWelcome_AddOnePerson ($sfx, $fields = FALSE)
	{
		$this->addInput('lastName'.$sfx, 'P????jmen??', self::INPUT_STYLE_STRING, 0, 80);
		$this->addInput('firstName'.$sfx, 'Jm??no', self::INPUT_STYLE_STRING, 0, 60);
		$this->addInput('birthdate'.$sfx, 'Datum narozen??', self::INPUT_STYLE_DATE);
		$this->addInput('idcn'.$sfx, '??islo OP', self::INPUT_STYLE_STRING, 0, 30);
		$this->addInput('street'.$sfx, 'Ulice', self::INPUT_STYLE_STRING, 0, 250);
		$this->addInput('city'.$sfx, 'M??sto', self::INPUT_STYLE_STRING, 0, 90);
		$this->addInput('zipcode'.$sfx, 'PS??', self::INPUT_STYLE_STRING, 0, 20);

		if ($fields === FALSE || in_array('email', $fields))
			$this->addInput('email'.$sfx, 'E-mail', self::INPUT_STYLE_STRING, 0, 60);
		if ($fields === FALSE || in_array('phone', $fields))
			$this->addInput('phone'.$sfx, 'Telefon', self::INPUT_STYLE_STRING, 0, 20);
		if ($fields === FALSE || in_array('bankacount', $fields))
			$this->addInput('bankaccount'.$sfx, '????slo ????tu', self::INPUT_STYLE_STRING, 0, 30);
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

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

		$newAddress = [];
		$newAddress ['street'] = $this->recData ['street'.$sfx];
		$newAddress ['city'] = $this->recData ['city'.$sfx];
		$newAddress ['zipcode'] = $this->recData ['zipcode'.$sfx];
		$newAddress ['country'] = $this->app()->cfgItem ('options.core.ownerDomicile', 'cz');
		$newAddress ['worldCountry'] = World::countryNdx($this->app(), $this->app()->cfgItem ('options.core.ownerDomicile', 'cz'));

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

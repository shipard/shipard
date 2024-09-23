<?php

namespace e10\users\libs;


use \Shipard\Base\Utility, \Shipard\Utils\Utils, \Shipard\Utils\Str;

/**
 * class Authenticator
 */
class Authenticator extends Utility
{
  var $sessionCookieName = 'shp-ng-sid';
  var $pwdMinLen = 8;

  CONST
    resUnknownError = 1,
    resPasswordsNotMatch = 2,
    resBlankPassword = 3,
    resRequestError = 4,
    resAccountIsActivated = 5,
    resPasswordIsTooShort = 6,
    resPasswordMustIncludeNumber = 7,
    resPasswordMustIncludeLetter = 8,
    resEmailIsBlank = 9,
    resUnknownUserEmail = 10,
    resAccountNotActivated = 11
    ;


  function checkSession()
  {
    $headers = Utils::getAllHeaders();
		if (isset ($headers['shpd-api-key']))
		{
			$ok = $this->checkRobot($headers['shpd-api-key']);
			if ($ok)
				return TRUE;
		}

    $sessionId = $this->testCookie ($this->sessionCookieName);

    if (!$sessionId)
      return FALSE;

    $sessionInfo = $this->db()->query('SELECT [user], [apiKey] FROM [e10_users_sessions] WHERE [ndx] = %s', $sessionId)->fetch();
    if ($sessionInfo)
    {
      $this->setUserInfo($sessionInfo['user'], $sessionInfo['apiKey']);
      return TRUE;
    }

    return FALSE;
  }

  function sessionInfo()
  {
    $sessionId = $this->testCookie ($this->sessionCookieName);

    if (!$sessionId)
      return NULL;

    $sessionInfo = $this->db()->query('SELECT * FROM [e10_users_sessions] WHERE [ndx] = %s', $sessionId)->fetch();
    if ($sessionInfo)
      return $sessionInfo->toArray();

    return NULL;
  }

  function checkUser($credentials)
  {
    $login = $credentials['login'] ?? NULL;
    if (!$login)
      return FALSE;
    $password = $credentials['password'] ?? NULL;
    if (!$password)
      return FALSE;

    $userInfo = $this->db()->query('SELECT * FROM [e10_users_users] WHERE [login] = %s', $login,
                                    ' AND [docState] = %i', 4000)->fetch();
    if (!$userInfo)
      return FALSE;

    $existedPassword = $this->db()->query('SELECT * FROM [e10_users_pwds] WHERE [user] = %i', $userInfo['ndx'])->fetch();

    if ($existedPassword)
    {
      if (password_verify($password, $existedPassword['password']))
      {
        $this->createNewSession($userInfo['ndx']);
        $this->setUserInfo($userInfo['ndx'], 0);

        return TRUE;
      }
    }

    return FALSE;
  }

  function checkUserPin($credentials)
  {
    $login = $credentials['login'] ?? NULL;
    if (!$login)
      return FALSE;
    $pin = $credentials['pin'] ?? NULL;
    if (!$pin)
      return FALSE;

    $userInfo = $this->db()->query('SELECT * FROM [e10_users_users] WHERE [login] = %s', $login,
                                    ' AND [docState] = %i', 4000)->fetch();
    if (!$userInfo)
      return FALSE;

    $existedPin = $this->db()->query('SELECT * FROM [e10_users_usersKeys] WHERE [keyType] = %i', 0,
                                      ' AND [user] = %i', $userInfo['ndx'],
                                      ' AND [docState] = %i', 4000)->fetch();
    if ($existedPin && $existedPin['key'] === $pin)
    {
      $this->createNewSession($userInfo['ndx']);
      $this->setUserInfo($userInfo['ndx'], 0);

      return TRUE;
    }

    return FALSE;
  }

  function checkRobot($apiKey)
  {
    if ($apiKey == '')
      return FALSE;

    $apiKeyInfo = $this->db()->query('SELECT * FROM [e10_users_apiKeys] WHERE [key] = %s', $apiKey, ' AND [docState] = %i', 4000)->fetch();
    if (!$apiKeyInfo)
      return FALSE;

    $userNdx = $apiKeyInfo['user'];
    $userInfo = $this->db()->query('SELECT * FROM [e10_users_users] WHERE [ndx] = %i', $userNdx,
                                   ' AND docState = %i', 4000,
                                   ' AND accState = %i', 1)->fetch();
    if (!$userInfo)
      return FALSE;
    if ($userInfo['userType'] != 1)
      return FALSE;

    $this->createNewSession($userInfo['ndx'], $apiKeyInfo['ndx']);
    $this->setUserInfo($userInfo['ndx'], $apiKeyInfo['ndx']);

    return TRUE;
  }

  function createNewSession($userNdx, $apiKeyNdx = 0)
  {
    $newSession = [
      'ndx' => Utils::createToken(40),
      'user' => $userNdx,
      'apiKey' => $apiKeyNdx,
      'created' => new \DateTime(),
    ];

    $this->db()->query('INSERT INTO [e10_users_sessions] ', $newSession);

    $sessionExpiration = $apiKeyNdx ? time()+60*60*24*365*5 : time()+60*60*24*14; // robots session expired after 5 years
    $this->setCookie($this->sessionCookieName, $newSession['ndx'], $sessionExpiration);
  }

  function setUserInfo($userNdx, $apiKeyNdx = 0)
  {
    $userRecData = $this->db()->query('SELECT * FROM [e10_users_users] WHERE [ndx] = %i', $userNdx)->fetch();

    // -- main roles
    $mainRoles = [];
    $q = [];
		array_push($q, 'SELECT links.*, [roles].systemId AS systemId');
		array_push($q, ' FROM e10_base_doclinks AS [links]');
		array_push($q, ' LEFT JOIN e10_users_roles AS [roles] ON links.dstRecId = [roles].ndx');
		array_push($q, ' WHERE dstTableId = %s', 'e10.users.roles');
		array_push($q, ' AND srcTableId = %s', 'e10.users.users');
		array_push($q, ' AND links.srcRecId = %i', $userNdx);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
			$mainRoles[] = $r['systemId'];

    $this->app->uiUser = [
      'ndx' => $userNdx, 'name' => $userRecData['fullName'],
      'apiKeyNdx' => $apiKeyNdx,
      'person' => $userRecData['person'],
      'login' => $userRecData['person'], 'email' => $userRecData['email'],
      'mainRoles' => $mainRoles,
    ];
  }

  function closeSession()
  {
    $sessionId = $this->testCookie ($this->sessionCookieName);
    $this->setCookie ($this->sessionCookieName, '', time() - 3600);
    $this->db()->query('DELETE FROM [e10_users_sessions] WHERE [ndx] = %s', $sessionId);
  }

	function testCookie ($cookieName)
	{
    return $_COOKIE [$cookieName] ?? NULL;
	}

	public function sessionCookieDomain ()
	{
		return $_SERVER['HTTP_HOST'];
	}

	public function sessionCookiePath ()
	{
    return ($this->app()->dsRoot === '') ? '/' : $this->app()->dsRoot;
	}

  public function setCookie (string $name, string $value, int $expires)
	{
		$options = [
			'expires' => $expires,
			'path' => $this->sessionCookiePath(),
			'domain' => $this->sessionCookieDomain(),
			'secure' => TRUE,
			'httponly' => TRUE,
			'samesite' => 'strict',
		];

		return \setCookie($name, $value, $options);
	}

  function checkActivation($credentials, &$msg)
  {
    $password1 = $credentials['password1'] ?? NULL;
    $password2 = $credentials['password2'] ?? NULL;
    $requestId = $credentials['requestId'] ?? NULL;

    if ($password1 === NULL || $password2 === NULL  || $requestId === NULL)
    {
      $msg = "Něco se pokazilo...";
      return self::resUnknownError;
    }

    if (trim($password1) === '')
    {
      $msg = "Heslo není vyplněno";
      return self::resBlankPassword;
    }

    if ($password1 !== $password2)
    {
      $msg = "Hesla se neshodují";
      return self::resPasswordsNotMatch;
    }

    if (Str::strlen($password1) < $this->pwdMinLen)
    {
      $msg = "Heslo je krátké";
      return self::resPasswordIsTooShort;
    }

    if (!preg_match("#[0-9]+#", $password1))
    {
      $msg = "Heslo musí obsahovat alespoň jednu číslici";
      return self::resPasswordMustIncludeNumber;
    }

    if (!preg_match("#[\\p{L}]+#", $password1))
    {
      $msg = "Heslo musí obsahovat alespoň jedno písmeno";
      return self::resPasswordMustIncludeLetter;
    }

		$tableRequests = new \e10\users\TableRequests($this->app());
    $requestInfo = $tableRequests->requestInfo($requestId, 'activate');
    if (($requestInfo['requestIsOk'] ?? 0) !== 1)
    {
      $msg = "Chyba požadavku";
      return self::resRequestError;
    }

    $existedPassword = $this->db()->query('SELECT * FROM [e10_users_pwds] WHERE [user] = %i', $requestInfo['userNdx'])->fetch();
    if ($existedPassword)
    {
      $msg = "Účet je již aktivován";
      return self::resAccountIsActivated;
    }

    $newPassword = password_hash($password1, PASSWORD_BCRYPT, ['cost' => 12]);
    $this->db()->query('INSERT INTO [e10_users_pwds] SET [user] = %i', $requestInfo['userNdx'], ', [password] = %s', $newPassword);

    $updateRequest = [
      'requestState' => 3,
      'tsFinished' => new \DateTime(),
    ];
    $this->db()->query('UPDATE [e10_users_requests] SET ', $updateRequest, ' WHERE ndx = %i', $requestInfo['requestNdx']);

    $updateUser = [
      'accState' => 1,
    ];
    $this->db()->query('UPDATE [e10_users_users] SET ', $updateUser, ' WHERE ndx = %i', $requestInfo['userNdx']);

    return 0;
  }

  function changePassword($credentials, &$msg)
  {
    $password1 = $credentials['password1'] ?? NULL;
    $password2 = $credentials['password2'] ?? NULL;
    $requestId = $credentials['requestId'] ?? NULL;

    if ($password1 === NULL || $password2 === NULL  || $requestId === NULL)
    {
      $msg = "Něco se pokazilo...";
      return self::resUnknownError;
    }

    if (trim($password1) === '')
    {
      $msg = "Heslo není vyplněno";
      return self::resBlankPassword;
    }

    if ($password1 !== $password2)
    {
      $msg = "Hesla se neshodují";
      return self::resPasswordsNotMatch;
    }

    if (Str::strlen($password1) < $this->pwdMinLen)
    {
      $msg = "Heslo je krátké";
      return self::resPasswordIsTooShort;
    }

    if (!preg_match("#[0-9]+#", $password1))
    {
      $msg = "Heslo musí obsahovat alespoň jednu číslici";
      return self::resPasswordMustIncludeNumber;
    }

    if (!preg_match("#[\\p{L}]+#", $password1))
    {
      $msg = "Heslo musí obsahovat alespoň jedno písmeno";
      return self::resPasswordMustIncludeLetter;
    }

		$tableRequests = new \e10\users\TableRequests($this->app());
    $requestInfo = $tableRequests->requestInfo($requestId, 'activate');
    if (($requestInfo['requestIsOk'] ?? 0) !== 1)
    {
      $msg = "Chyba požadavku";
      return self::resRequestError;
    }

    $existedPassword = $this->db()->query('SELECT * FROM [e10_users_pwds] WHERE [user] = %i', $requestInfo['userNdx'])->fetch();
    if (!$existedPassword)
    {
      $msg = "Účet není aktivován";
      return self::resAccountNotActivated;
    }

    $newPassword = password_hash($password1, PASSWORD_BCRYPT, ['cost' => 12]);
    $this->db()->query('UPDATE [e10_users_pwds] SET [password] = %s', $newPassword, ' WHERE ndx = %i', $existedPassword['ndx']);

    $updateRequest = [
      'requestState' => 3,
      'tsFinished' => new \DateTime(),
    ];
    $this->db()->query('UPDATE [e10_users_requests] SET ', $updateRequest, ' WHERE ndx = %i', $requestInfo['requestNdx']);

    return 0;
  }

  function sendLostPassword($credentials, \Shipard\UI\ng\Router $router)
  {
    $userEmail = $credentials['userEmail'] ?? NULL;

    if ($userEmail === NULL)
      return self::resUnknownError;

    if (trim($userEmail) === '')
      return self::resEmailIsBlank;

    $existedUser = $this->db()->query('SELECT * FROM [e10_users_users] WHERE [email] = %s', $userEmail)->fetch();
    if (!$existedUser)
      return self::resUnknownUserEmail;

    if ($existedUser['accState'] == 0)
      return self::resAccountNotActivated;

    $tableRequests = new \e10\users\TableRequests($this->app());
    $newRequest = ['user' => $existedUser['ndx'], 'ui' => $router->uiCfg ['ndx'], 'requestType' => 1];
    $newRequestNdx = $tableRequests->dbInsertRec($newRequest);

    $sendRequestEngine = new \e10\users\libs\SendRequestEngine($this->app());
    $sendRequestEngine->setRequestNdx($newRequestNdx);
    $sendRequestEngine->sendRequest();

    return 0;
  }
}


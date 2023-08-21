<?php

namespace Shipard\Application;


class Authenticator
{
	/** @var \E10\Application */
	public $app;
	public $options;
	public $formErrors = array ();

	CONST actNone = 0, actLocal = 1, actShipard = 2;
	CONST acsNone = 0, acsActive = 1;
	CONST dactShipard = 0, dactLocal = 1;

	public function __construct ($app)
	{
		$this->app = $app;
		$this->options = $this->app->appSkeleton ['userManagement'];
	}

	function authenticateUser (Application $app, array &$credentials) {return FALSE;}
	function authenticateApiKey (Application $app, $apiKey) {return FALSE;}
	function authenticateRobot(Application $app, $apiKey) {return FALSE;}
	function authenticateSession (Application $app, $sessionId) {return FALSE;}
	function checkUserRegistration () {return FALSE;}
	function startSession (Application $app, $userNdx, $newSessionId) {return '';}
	public function userGroups ($forUserNdx = 0) {return array();}
	function userHasRole ($app, $role) {return FALSE;}

	public function passwordHash(string $password)
	{
		return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
	}

	public function option ($key, $defaultValue = 0)
	{
		if (isset ($this->options [$key]))
			return $this->options [$key];
		return $defaultValue;
	}

	public function doIt ()
	{
		if ($this->option ('pathBase') != $this->app->requestPath (0))
			return NULL;

		$referer = $this->loginReferer ();

		// logout check
		if ($this->option ('pathLogoutCheck') == $this->app->requestPath (1))
		{
			$this->app->setCookie ($this->app->sessionCookieName (), '', time() - 3600);
			if ($this->app->testGetParam('m') === '1')
				header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . '/mapp/login');
			elseif ($this->app->testGetParam('loginUrl') !== '')
				header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $this->app->testGetParam('loginUrl'));
			else
				header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . '/' . $this->option ('pathBase') . '/' . $this->option ('pathLogin'));
			return new Response ($this->app, 'bye...', 302);
		} // logout check

		// login check
		if ($this->option ('pathLoginCheck') == $this->app->requestPath (1))
		{
			$auth = [
					'login' => $this->app->testPostParam('login'),
					'password' => $this->app->testPostParam('password'),
					'pin' => $this->app->testPostParam('pin')
			];

			if ($this->authenticateUser ($this->app, $auth))
			{
				header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $referer);
				return new Response ($this->app, "login was successfully", 302);
			}
			if ($referer === '/mapp')
				header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . "/mapp/login?from=1");
			//elseif (str_starts_with($referer, '/ui'))
			//	header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . "/ui/login?from=1");
			else
			{
				header('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . "/" . $this->option('pathBase') . "/" . $this->option('pathLogin'));
			}
			return new Response ($this->app, "bad login", 307);
		} // login check

		// -- robots
		if ($this->option ('pathLoginRobots') == $this->app->requestPath (1))
		{
			$apiKey = $this->app->requestPath (2);
			if ($this->authenticateRobot($this->app, $apiKey))
			{
				header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $referer);
				return new Response ($this->app, "login was successfully", 302);
			}
		}

		return NULL;
	} // doIt

	public function checkRolesDependencies (&$userRoles, $allRoles)
	{
		$added = 0;

		foreach ($userRoles as $roleId)
		{
			if (!isset($allRoles[$roleId]))
				continue;
			$r = $allRoles[$roleId];
			if (!isset ($r['roles']))
				continue;
			foreach ($r['roles'] as $newRoleId)
			{
				if (in_array($newRoleId, $userRoles))
					continue;
				$userRoles[] = $newRoleId;
				$added++;
			}
			if ($added)
				$added = $this->checkRolesDependencies ($userRoles, $allRoles);
		}

		return $added;
	}

	public function formCode ($formType)
	{
		$page = array ();
		$page ['html'] = "něco se pokazilo";
		$page ['title'] = 'Přihlášení';
		return $page;
	}

	public function loginReferer ()
	{
		$baseUrlRoot = $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot;
		$referer = $this->app->testPostParam ('from');

		if (($referer == '') && (isset ($this->app->requestPath[2])))
		{
			$pos = 2;
			if ($this->option ('pathLoginRobots') === $this->app->requestPath (1))
				$pos++;
			while ((isset ($this->app->requestPath[$pos])) && (strlen ($this->app->requestPath[$pos])))
				$referer .= "/" . $this->app->requestPath[$pos++];
		}

		if (($referer == '') && (isset ($_SERVER['HTTP_REFERER'])))
			if (substr ($_SERVER['HTTP_REFERER'], 0, strlen ($baseUrlRoot)) == $baseUrlRoot)
				$referer = substr ($_SERVER['HTTP_REFERER'], strlen ($baseUrlRoot));

		if ($referer == '')
			$referer = '/';

		if (substr ($referer, 0, strlen ($this->app->appSkeleton['userManagement']['pathBase'])+1) == '/' . $this->app->appSkeleton['userManagement']['pathBase'])
			$referer = '/';

		return $referer;
	}


	function getSystemPage ()
	{
		if ($this->option ('pathBase') != $this->app->requestPath (0))
			return NULL;

		if ($this->option ('pathLogin') == $this->app->requestPath (1))
		{
			if ($this->app->requestPath (2) === 'mapp')
			{
				$this->app->mobileMode = TRUE;
			}
		}

		if ($this->option ('pathLogin') == $this->app->requestPath (1))
			return $this->formCode ('login');
		if ($this->option ('pathLogout') == $this->app->requestPath (1))
			return $this->formCode ('logout');
		if ($this->option ('pathRegistration') == $this->app->requestPath (1))
			return $this->formCode ('registration');
		if ($this->option ('pathRequest') == $this->app->requestPath (1))
			return $this->formCode ('request');
		if ($this->option ('pathCheckUserRegistration') == $this->app->requestPath (1))
			return $this->checkUserRegistration ();
		if ($this->option ('pathLostPassword') == $this->app->requestPath (1))
			return $this->formCode ('lostPassword');
		if ($this->option ('pathSetLanguage') == $this->app->requestPath (1))
			return $this->setLanguage();

		return NULL;
	}

	function isSystemPage ()
	{
		if ($this->option ('pathBase') == $this->app->requestPath (0))
			return TRUE;
		return FALSE;
	}

	public function runAsUser($userNdx)
	{
	}

	function setLanguage()
	{
		$langId = $this->app->requestPath(2);
		$this->app->setCookie ('e10-user-language', $langId, time() + 3999 * 86400);

		$redirTo = $_SERVER['HTTP_REFERER'];
		header ('Location: ' . $redirTo);
		die();
	}
}


<?php

namespace e10\web;

use e10\utils, e10\Utility;


/**
 * Class WebAuthenticator
 * @package e10\web
 */
class WebAuthenticator extends Utility
{
	var $options = NULL;
	/** @var \e10\web\webPages */
	var $webEngine = NULL;

	var $personNdx = 0;
	var $sessionId = '';
	var $session = NULL;

	public function init($webEngine)
	{
		$this->options = [
			'pathBase' => 'user',
			'pathLoginViaKey' => 'k',
			'pathLogin' => 'login',
			'pathLoginCheck' => 'login-check',
			'pathLogoutCheck' => 'logout-check',
		];

		$this->webEngine = $webEngine;
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
		{
			$as = $this->authenticateSession ();
			if (!$as && $this->webEngine->loginRequired)
			{
				$loginPath = "/" . $this->option ('pathBase').'/'.$this->option ('pathLogin');
				$fromPath = implode ('/', $this->app->requestPath);
				header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST']. $this->app->urlRoot . $loginPath . "/" . $fromPath);
				die();
			}

			return NULL;
		}

		$referer = $this->loginReferer ();

		// -- login via url key
		if ($this->option ('pathLoginViaKey') === $this->app->requestPath (1))
		{
			return $this->authenticateUrlKey();
		}

		// -- logout check
		if ($this->option ('pathLogoutCheck') === $this->app()->requestPath (1))
		{
			setCookie ($this->sessionCookieName (), '', time()-3600, '/', $this->sessionCookieDomain(), TRUE, 1);
			header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . '/' . $this->option ('pathBase') . '/' . $this->option ('pathLogin'));
			die();
		}

		// -- login check
		if ($this->option ('pathLoginCheck') === $this->app->requestPath (1))
		{
			if ($this->authenticateUser ())
			{
				header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . $referer);
				die();
			}
			header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . "/" . $this->option ('pathBase') . "/" . $this->option ('pathLogin').'?from=1');
			die();
		}

		return NULL;
	}

	function getSystemPage ()
	{
		if ($this->option ('pathBase') != $this->app->requestPath (0))
			return NULL;

		if ($this->option ('pathLogin') == $this->app->requestPath (1))
			return $this->formCode ('login');
		if ($this->option ('pathLogout') == $this->app->requestPath (1))
			return $this->formCode ('logout');
		if ($this->option ('pathRequest') == $this->app->requestPath (1))
			return $this->formCode ('request');
		if ($this->option ('pathLostPassword') == $this->app->requestPath (1))
			return $this->formCode ('lostPassword');

		if ($this->app->cfgItem ('enableUserRegistration', 0))
		{
			if ($this->option('pathRegistration') == $this->app->requestPath(1))
				return $this->formCode('registration');
			if ($this->option('pathCheckUserRegistration') == $this->app->requestPath(1))
				return $this->checkUserRegistration();
		}

		return NULL;
	}

	function isSystemPage ()
	{
		if ($this->option ('pathBase') == $this->app->requestPath (0))
			return TRUE;
		return FALSE;
	}

	public function loginReferer ()
	{
		$baseUrlRoot = $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot;
		$referer = $this->app()->testPostParam ('from');

		if (($referer == '') && (isset ($this->app->requestPath[2])))
		{
			$pos = 2;
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

	public function sessionCookieName ()
	{
		$id = '_shp_web_sid_'.$this->webEngine->serverInfo['ndx'];
		return $id;
	}

	public function sessionCookiePath ()
	{
		return '/';
	}

	public function sessionCookieDomain ()
	{
		return $_SERVER['HTTP_HOST'];
	}

	function authenticateUrlKey ()
	{
		$urlKeyValue = $this->app()->requestPath(2);

		$q [] = 'SELECT wuKeys.*, persons.[fullName] AS personName';
		array_push ($q, ' FROM [e10_web_wuKeys] AS wuKeys');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON wuKeys.person = persons.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [webServer] = %i', $this->webEngine->serverInfo['ndx']);
		array_push ($q, ' AND [keyValue] = %s', $urlKeyValue);
		array_push ($q, ' AND [wuKeys].[docStateMain] = %i', 2);

		$exist = $this->db()->query($q)->fetch();
		if ($exist)
		{
			$this->personNdx = $exist['person'];
			$this->checkSession(2, $urlKeyValue);

			header ('Location: ' . $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot);
			die();
		}

		$page = [];
		$page ['text'] = "Přístup zamítnut";
		$page ['title'] = 'Neznámý uživatel';
		$page ['status'] = 404;

		return $page;
	}

	function authenticateSession ()
	{
		$this->sessionId = $this->app()->testCookie ($this->sessionCookieName ());
		if ($this->sessionId !== '')
		{
			$existedSession = $this->db()->query('SELECT * FROM [e10_web_wuSessions] WHERE ndx = %s', $this->sessionId, ' AND webServer = %i', $this->webEngine->serverInfo['ndx'])->fetch();
			if ($existedSession)
			{
				$this->personNdx = $existedSession['person'];
				$this->session = $existedSession->toArray();
				$this->setUserInfo();
				return TRUE;
			}
		}

		return FALSE;
	}

	function authenticateUser()
	{
		$authKey = $this->app()->testPostParam('authKey', NULL);

		if ($authKey !== NULL)
		{
			if ($this->app()->model()->module ('mac.access') === FALSE)
				return FALSE;

			/** @var \mac\access\TableTags $tableTags */
			$tableTags = $this->app()->table ('mac.access.tags');
			if ($tableTags === NULL)
				return FALSE;
			$res = $tableTags->tagPerson($authKey);

			if ($res === FALSE)
				return FALSE;

			$this->personNdx = $res['person'];
			$this->startSession(3, '', $res['tag']);

			return TRUE;
		}

		return FALSE;
	}

	function checkSession ($loginType, $loginKeyValue)
	{
		$this->sessionId = $this->app()->testCookie ($this->sessionCookieName ());
		if ($this->sessionId !== '')
		{
			$existedSession = $this->db()->query('SELECT * FROM [e10_web_wuSessions] WHERE ndx = %s', $this->sessionId, ' AND webServer = %i', $this->webEngine->serverInfo['ndx'])->fetch();
			if (!$existedSession || $existedSession['person'] !== $this->personNdx)
			{
				$this->startSession($loginType, $loginKeyValue);
				return;
			}
			$this->session = $existedSession->toArray();
		}

		$this->startSession($loginType, $loginKeyValue);
	}

	function startSession($loginType, $loginKeyValue, $tagNdx = 0)
	{
		$this->sessionId = utils::createToken(40);
		$newSessionItem = [
			'ndx' => $this->sessionId, 'created' => new \DateTime(),
			'webServer' => $this->webEngine->serverInfo['ndx'], 'person' => $this->personNdx,
			'loginType' => $loginType, 'loginKeyValue' => $loginKeyValue
		];

		if ($tagNdx)
			$newSessionItem['loginTag'] = $tagNdx;

		$this->db()->query('INSERT INTO [e10_web_wuSessions]', $newSessionItem);

		$sessionExpiration = time()+128*60*60*24;

		setCookie($this->sessionCookieName(), $this->sessionId, $sessionExpiration, '/', $this->sessionCookieDomain(), TRUE, 1);
		$this->session = $newSessionItem;
		$this->setUserInfo();
	}

	function setUserInfo()
	{
		$person = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE [ndx] = %i', $this->personNdx)->fetch();
		if (!$person)
		{
			$this->personNdx = 0;
			return;
		}

		$userData = ['id' => $this->personNdx, 'login' => '------', 'name' => $person['fullName'], 'roles' => ['host']];
		$this->app()->user ()->setData ($userData);
	}

	function formCode($formType)
	{
		if ($formType === 'login')
			return $this->formCodeLogin();

		$page = [];
		$page ['text'] = "Stránka neexistuje";
		$page ['title'] = 'Stránka neexistuje';
		$page ['status'] = 404;

		return $page;
	}

	function formCodeLogin()
	{
		$loginType = '';
		if (isset($this->webEngine->serverInfo['authTypeKeyId']) && $this->webEngine->serverInfo['authTypeKeyId'])
			$loginType = 'key';
		elseif (isset($this->webEngine->serverInfo['authTypePassword']) && $this->webEngine->serverInfo['authTypePassword'])
			$loginType = 'password';

		$page = [];

		if ($loginType === 'key')
		{
			$form = new \e10\web\webForms\LoginKey($this->webEngine);

			$page ['text'] = $form->createFormCode();
			$page ['title'] = 'Přihlaste se';
			$page ['status'] = 200;

			return $page;
		}

		$page ['text'] = "Stránka neexistuje";
		$page ['title'] = 'Stránka neexistuje';
		$page ['status'] = 404;

		return $page;
	}
}


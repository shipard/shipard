<?php

namespace Shipard\UI\ng;

use E10\utils;


/**
 * Class StartMenu
 * @package mobileui
 */
class Login extends \Shipard\UI\ng\AppPageBlank
{
	var $mode = '';
	var $deviceId = '';
	var $workplace = NULL;
	var $users = [];

	public function createContent ()
	{
		$this->mode = $this->app->requestPath(2);

		$headers = utils::getAllHeaders();

		if (isset($headers['e10-device-id']))
			$this->deviceId = $headers['e10-device-id'];
		else
			$this->deviceId = $this->app->testCookie ('_shp_did');

		if ($this->deviceId === '')
		{
			return;
		}

		$this->workplace = $this->app->searchWorkplace($this->deviceId);

		if (!$this->workplace)
			return;

		if (isset($this->workplace['users']))
		{
			$q[] = 'SELECT persons.fullName, persons.firstName, persons.lastName, persons.login FROM e10_persons_persons AS persons';
			array_push($q, ' WHERE persons.ndx IN %in', $this->workplace['users']);
			array_push($q, ' ORDER BY persons.fullName', " LIMIT 0, 10");

			$rows = $this->db()->query($q);
			forEach ($rows as $r)
			{
				$this->users[] = $r->toArray();
			}
		}

		// -- user info
		$this->pageInfo['userInfo'] = [];
	}

	public function createContentCodeInside ()
	{
		$c = '';

		if ($this->mode === 'workplace' && !count($this->users))
		{
			$c .= "<div class='e10-mui-login-users'>";

			$c .= '<p>'.utils::es ('Zařízení není zařazeno k žádnému pracovišti.').'</p>';
			$c .= '<p>'.utils::es ('ID zařízení: ' . $this->deviceId).'</p>';

			$c .= "</div>";
		}
		else
		if (count($this->users))
		{
			$c .= "<div class='e10-mui-login-users'>";
			foreach ($this->users as $user)
			{
				$c .= "<span class='user e10-trigger-action' data-action='workspace-login'";
				$c .= " data-login='" . utils::es($user['login']) . "'";
				$c .= '>';
				$c .= "<div class='u'>";
				$c .= "<span class='name'>" . utils::es($user['fullName']) . '</span>';
				$c .= '</div>';
				$c .= '</span>';
			}

			$c .= "<form class='e10-mui-login-form' name='e10-mui-login-form' method='POST' action='{$this->app->urlRoot}/user/login-check/ui' style='display: none;'>";
				$c .= "<input type='hidden' name='login' id='e10-login-user'>";
				$c .= "<input type='hidden' name='pin' id='e10-login-pin'>";
			$c .= '</form>';

			if ($this->app->testGetParam ("from", NULL) != NULL)
				$c .= "<div class='h1 m e10-error center'>Chybně zadaný PIN</div>";

			$c .= '</div>';
		}
		else
		{
			$userValue = $this->app->cfgItem ('autoLoginUser', FALSE);
			$userValueParam = ($userValue) ? " value='".utils::es($userValue)."'": '';
			$passwordValue = $this->app->cfgItem ('autoLoginPassword', FALSE);
			$passwordValueParam = ($passwordValue) ? " value='".utils::es($passwordValue)."'": '';

			$c .= "<form class='e10-mui-login-form' method='POST' action='{$this->app->urlRoot}/user/login-check/ui'>";
			if ($this->app->testGetParam ("from", NULL) != NULL)
				$c .= "<div class='m e10-error'>chybné jméno nebo heslo</div>";

			$c .= "<label for='e10-login-user'>Email</label><input type='email' name='login' id='e10-login-user'$userValueParam>";
			$c .= "<label for='e10-login-password'>Heslo</label><input type='password' name='password' id='e10-login-password'$passwordValueParam>";

			$referer = $this->loginReferer();
			$c .= "<input type='text' name='from' value='$referer'>";

			$c .= "<div class='b'>";
			$c .= "<button type='submit' class='btn btn-primary'>Přihlásit</button>";
			$c .= '</div>';

			$c .= '</form>';
		}
		return $c;
	}

	public function title1 ()
	{
		return $this->app->cfgItem ('options.core.ownerShortName');
	}

	public function title2 ()
	{
		if ($this->workplace)
			return $this->workplace['name'];

		return 'Přihlášení';
	}

	public function leftPageHeaderButton ()
	{
		$lmb = ['icon' => 'system/iconHamburgerMenu', 'action' => 'app-menu'];
		return $lmb;
	}

	public function rightPageHeaderButtons ()
	{
		return FALSE;
	}

	public function pageTitle()
	{
		return $this->title1();
	}

	public function pageType () {return 'terminal';}

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
}


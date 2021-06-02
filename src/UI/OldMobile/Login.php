<?php

namespace Shipard\UI\OldMobile;

use E10\utils;


/**
 * Class StartMenu
 * @package mobileui
 */
class Login extends \Shipard\UI\OldMobile\PageObject
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
			$this->deviceId = $this->app->testCookie ('e10-deviceId');

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

			$c .= "<form class='e10-mui-login-form' name='e10-mui-login-form' method='POST' action='{$this->app->urlRoot}/user/login-check/mapp' style='display: none;'>";
				$c .= "<input type='hidden' name='login' id='e10-login-user'>";
				$c .= "<input type='hidden' name='pin' id='e10-login-pin'>";
			$c .= '</form>';

			if ($this->app->testGetParam ("from", NULL) != NULL)
				$c .= "<div class='m e10-error'>chybně zadaný pin</div>";

			$c .= '</div>';
		}
		else
		{
			$userValue = $this->app->cfgItem ('autoLoginUser', FALSE);
			$userValueParam = ($userValue) ? " value='".utils::es($userValue)."'": '';
			$passwordValue = $this->app->cfgItem ('autoLoginPassword', FALSE);
			$passwordValueParam = ($passwordValue) ? " value='".utils::es($passwordValue)."'": '';

			$c .= "<form class='e10-mui-login-form' method='POST' action='{$this->app->urlRoot}/user/login-check/mapp'>";
			if ($this->app->testGetParam ("from", NULL) != NULL)
				$c .= "<div class='m e10-error'>chybné jméno nebo heslo</div>";

			$c .= "<label for='e10-login-user'>Email</label><input type='email' name='login' id='e10-login-user'$userValueParam>";
			$c .= "<label for='e10-login-password'>Heslo</label><input type='password' name='password' id='e10-login-password'$passwordValueParam>";

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
		if ($this->app->testGetParam('standaloneApp') === '1')
			return FALSE;

		$rmbs = [];
		if ($this->app->clientType [1] === 'cordova')
		{
			if ($this->mode === '')
				$b = ['icon' => 'icon-times', 'url' => 'index.html', 'backButton' => 1];
			else
				$b = ['icon' => 'icon-refresh', 'url' => 'index.html'];
		}
		else
		{
			$b = ['icon' => 'system/actionClose', 'url' => 'https://m.shipard.com'];
		}
		$rmbs[] = $b;

		return $rmbs;
	}

	public function pageTitle()
	{
		return $this->title1();
	}

	public function pageType () {return 'terminal';}
}


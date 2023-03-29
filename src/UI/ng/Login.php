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

		if ($this->mode === 'set-workplace')
		{
			$workplaceId = $this->app->requestPath(3);
			$this->app->setCookie ('_shp_gwid', $workplaceId, time() + 10 * 365 * 86400);

			$redirTo = $this->app->urlProtocol . $_SERVER['HTTP_HOST']. $this->app->urlRoot . '/ui/login';
			$lpid = 4;
			while ($this->app->requestPath($lpid) !== '')
				$redirTo .= '/'.$this->app->requestPath($lpid++);

			header ('Location: '.$redirTo);
			die();
		}

		$headers = utils::getAllHeaders();

		$workplaceGID = $this->app->testCookie ('_shp_gwid');
		if ($workplaceGID !== '')
			$this->workplace = $this->app->searchWorkplaceByGID($workplaceGID);

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

			if (count($this->users))
				$this->mode = 'workplace';
		}

		// -- user info
		$this->pageInfo['userInfo'] = [];
	}

	public function createContentCodeInside ()
	{
		if ($this->mode === 'workplace')
			return $this->createContentCodeInside_Workplace();

		$c = '';

		$userValue = $this->app->cfgItem ('autoLoginUser', FALSE);
		$userValueParam = ($userValue) ? " value='".utils::es($userValue)."'": '';
		$passwordValue = $this->app->cfgItem ('autoLoginPassword', FALSE);
		$passwordValueParam = ($passwordValue) ? " value='".utils::es($passwordValue)."'": '';

		$c .= "<div class='container d-flex justify-content-center align-items-center' style='height: 100vh;'>";
		$c .= "<div class='card' style='min-width: 35em; max-width: 90vw;'>";
		$c .= "<div class='card-header'>";
    $c .= Utils::es('Přihlášení');
  	$c .= '</div>';
		$c .= "<div class='card-body'>";
		if ($this->app->testGetParam ("from", NULL) != NULL)
			$c .= "<div class='alert alert-danger'>Chybný přihlašovací e-mail nebo heslo</div>";
		$c .= "<form class='_form-floating _mb-3' method='POST' action='{$this->app->urlRoot}/user/login-check/ui'>";

		$c .=	"<div class='form-floating mb-3'>";
		$c .= "<input type='email' class='form-control' name='login' placeholder='name@example.com' id='e10-login-user'$userValueParam>\n";
		$c .= "<label for='e10-login-user'>E-mail</label>\n";
		$c .= '</div>';
		$c .=	"<div class='form-floating mb-3'>";
		$c .= "<input type='password' name='password' class='form-control' placeholder='Heslo' id='e10-login-password'$passwordValueParam>\n";
		$c .= "<label for='e10-login-password'>Heslo</label>\n";
		$c .= '</div>';
		$referer = $this->loginReferer();
		$c .= "<input type='hidden' name='from' value='$referer'>";

		$c .= "<div class='b'>";
		$c .= "<button type='submit' class='btn btn-primary'>Přihlásit</button>";
		$c .= '</div>';

		$c .= '</form>';

		$c .= '</div>';
		$c .= '</div>';
		$c .= '</div>';

		return $c;
	}

	public function createContentCodeInside_Workplace ()
	{
		$c = '';

		$c .= "<div class='container d-flex justify-content-center align-items-center flex-row flex-wrap: wrap;' style='height: 100vh;'>";
		$c .= "<div class='shp-workplace-login-users'>";
		if ($this->app->testGetParam ("from", NULL) != NULL)
			$c .= "<div class='alert alert-danger flex-grow-1 w-100'>Chybně zadaný PIN</div>";

		foreach ($this->users as $user)
		{
			$c .= "<button class='btn btn-lg btn-primary user shp-app-action' data-action='workplaceLogin'";
			$c .= " data-login='" . utils::es($user['login']) . "'";
			$c .= '>';
			$c .= utils::es($user['fullName']);
			$c .= '</button>';
		}

		$c .= "<form class='e10-mui-login-form' name='e10-mui-login-form' method='POST' action='{$this->app->urlRoot}/user/login-check/ui' style='display: none;'>";
			$c .= "<input type='hidden' name='login' id='e10-login-user'>";
			$c .= "<input type='hidden' name='pin' id='e10-login-pin'>";
			$referer = $this->loginReferer();
			$c .= "<input type='hidden' name='from' value='$referer'>";
		$c .= '</form>';

		$c .= '</div>';
		$c .= '</div>';

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


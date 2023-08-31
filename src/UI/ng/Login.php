<?php

namespace Shipard\UI\ng;

use \Shipard\Utils\Utils;
use \e10\users\libs\Authenticator;


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
		$this->mode = $this->uiRouter->urlPart(1);

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

		$headers = Utils::getAllHeaders();

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

		$referer = $this->loginReferer();
		$from = $this->app->testGetParam ('from');

		if ($this->mode === 'login')
		{
			$templateStr = file_get_contents(__SHPD_ROOT_DIR__.'src/UI/ng/subtemplates/'.'user-login.mustache');
			$this->uiTemplate->data['login']['referer'] = $referer;
			$this->uiTemplate->data['login']['error'] = 0;
			if ($from !== '')
			{
				$this->uiTemplate->data['login']['error'] = 1;
				$this->uiTemplate->data['login']['errorMsg'] = 'Chybný přihlašovací e-mail nebo heslo';
			}
			$c = $this->uiTemplate->render($templateStr);
		}

		$tableRequests = new \e10\users\TableRequests($this->app());
		if ($this->mode === 'activate')
		{
			$requestId = $this->uiRouter->urlPart(2);
			$requestInfo = $tableRequests->requestInfo($requestId, '');

			$templateStr = $this->subTemplateStr('src/UI/ng/subtemplates/user-activate');

			$this->uiTemplate->data['request'] = $requestInfo;
			$this->uiTemplate->data['requestTest'] = json_encode($requestInfo);

			if ($from !== '')
			{
				$this->uiTemplate->data['login']['error'] = 1;
				$errorNumber = intval($from);
				if ($errorNumber === Authenticator::resPasswordsNotMatch)
					$this->uiTemplate->data['login']['errorMsg'] = 'Hesla se neshodují';
				elseif ($errorNumber === Authenticator::resBlankPassword)
					$this->uiTemplate->data['login']['errorMsg'] = 'Heslo není vyplněno';
				elseif ($errorNumber === Authenticator::resPasswordIsTooShort)
					$this->uiTemplate->data['login']['errorMsg'] = 'Heslo musí mít alespoň 8 znaků';
				elseif ($errorNumber === Authenticator::resPasswordMustIncludeNumber)
					$this->uiTemplate->data['login']['errorMsg'] = 'Heslo musí obsahovat alespoň jednu číslici';
				elseif ($errorNumber === Authenticator::resPasswordMustIncludeLetter)
					$this->uiTemplate->data['login']['errorMsg'] = 'Heslo musí obsahovat alespoň jedno písmeno';
				elseif ($errorNumber === Authenticator::resRequestError)
				{
					$this->uiTemplate->data['login']['errorMsg'] = 'Chyba požadavku';
				}
			}
			$c = $this->uiTemplate->render($templateStr);
		}

		if ($this->mode === 'send-lost-password')
		{
			$templateStr = file_get_contents(__SHPD_ROOT_DIR__.'src/UI/ng/subtemplates/'.'user-send-lost-password.mustache');
			if ($from !== '')
			{
				$errorNumber = intval($from);
				if ($errorNumber === Authenticator::resEmailIsBlank)
					$this->uiTemplate->data['login']['errorMsg'] = 'E-mail není vyplněn';
				elseif ($errorNumber === Authenticator::resUnknownUserEmail)
					$this->uiTemplate->data['login']['errorMsg'] = 'Tento e-mail není registrován.';
				elseif ($errorNumber === Authenticator::resAccountNotActivated)
					$this->uiTemplate->data['login']['errorMsg'] = 'Váš účet zatím nebyl aktivován.';
				else
					$this->uiTemplate->data['login']['errorMsg'] = 'Něco se pokazilo';

				$this->uiTemplate->data['login']['error'] = 1;
			}
			$c = $this->uiTemplate->render($templateStr);
		}

		if ($this->mode === 'change-password')
		{
			$requestId = $this->uiRouter->urlPart(2);
			$requestInfo = $tableRequests->requestInfo($requestId, '');
			$templateStr = file_get_contents(__SHPD_ROOT_DIR__.'src/UI/ng/subtemplates/'.'user-change-password.mustache');
			$this->uiTemplate->data['request'] = $requestInfo;
			$this->uiTemplate->data['requestTest'] = json_encode($requestInfo);

			if ($from !== '')
			{
				$this->uiTemplate->data['login']['error'] = 1;
				$errorNumber = intval($from);
				if ($errorNumber === Authenticator::resPasswordsNotMatch)
					$this->uiTemplate->data['login']['errorMsg'] = 'Hesla se neshodují';
				elseif ($errorNumber === Authenticator::resBlankPassword)
					$this->uiTemplate->data['login']['errorMsg'] = 'Heslo není vyplněno';
				elseif ($errorNumber === Authenticator::resPasswordIsTooShort)
					$this->uiTemplate->data['login']['errorMsg'] = 'Heslo musí mít alespoň 8 znaků';
				elseif ($errorNumber === Authenticator::resPasswordMustIncludeNumber)
					$this->uiTemplate->data['login']['errorMsg'] = 'Heslo musí obsahovat alespoň jednu číslici';
				elseif ($errorNumber === Authenticator::resPasswordMustIncludeLetter)
					$this->uiTemplate->data['login']['errorMsg'] = 'Heslo musí obsahovat alespoň jedno písmeno';
				elseif ($errorNumber === Authenticator::resRequestError)
				{
					$this->uiTemplate->data['login']['errorMsg'] = 'Chyba požadavku';
				}
			}
			$c = $this->uiTemplate->render($templateStr);
		}


		if ($this->mode === 'send-lost-password-done')
		{
			$templateStr = file_get_contents(__SHPD_ROOT_DIR__.'src/UI/ng/subtemplates/'.'user-send-lost-password-done.mustache');
			$c = $this->uiTemplate->render($templateStr);
		}

		return $c;
	}

	protected function subTemplateStr($stId)
	{
		$templateStr = file_get_contents(__SHPD_ROOT_DIR__.'/'.$stId.'.mustache');
		return $templateStr;
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
			$c .= " data-login='" . Utils::es($user['login']) . "'";
			$c .= '>';
			$c .= Utils::es($user['fullName']);
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
			$pos = 3;
			while ((isset ($this->app->requestPath[$pos])) && (strlen ($this->app->requestPath[$pos])))
				$referer .= "/" . $this->app->requestPath[$pos++];
		}

		if (($referer == '') && (isset ($_SERVER['HTTP_REFERER'])))
			if (substr ($_SERVER['HTTP_REFERER'], 0, strlen ($baseUrlRoot)) == $baseUrlRoot)
				$referer = substr ($_SERVER['HTTP_REFERER'], strlen ($baseUrlRoot));

		if (str_starts_with ($referer, '/user/') || str_starts_with ($referer, '/auth/'))
			$referer = '';

		if ($referer == '/')
			$referer = '';

		return $referer;
	}
}


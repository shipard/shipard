<?php

namespace e10\web\webForms;
use \e10\utils;


/**
 * Class LoginKey
 * @package e10\web\webForms
 */
class LoginKey extends \e10\web\webForms\Base
{
	var $authenticator;

	public function createFormCode ()
	{
		$this->authenticator = $this->webEngine->authenticator;

		$referer = $this->authenticator->loginReferer ();

		$c = '';
		$c .= "<form class='form-horizontal' method='POST' action='{$this->app->urlRoot}/" . $this->authenticator->option ('pathBase') . "/" . $this->authenticator->option ('pathLoginCheck') . "' id='e10-lf-authKey' autocomplete='off'>";

		if ($this->app->testGetParam ("from") != '')
		{
			$c .= "<div class='alert alert-danger'>chybný přihlašovací klíč</div>";
		}
		$c .= "<fieldset>";

		$c .= "<input class='form-control' placeholder='přiložte kartu ke čtečce' onblur=\"setTimeout(function() { $('#authKey').focus(); },1)\" id='authKey' name='authKey' value='' autofocus='autofocus' type='text' autocomplete='new-none' style='text-align: center; background-color: lightblue; border:none;color: transparent;text-shadow: 0 0 0 black;'/>";

		$c .= "<input type='hidden' name='from' value='$referer'>";
		$c .= "</fieldset></form>";

		return $c;
	}
}



<?php

namespace Shipard\UI\ng;


/**
 * class Auth
 */
class Auth extends \Shipard\UI\ng\AppPageBlank
{
	var $mode = '';
	var $deviceId = '';
	var $workplace = NULL;
	var $users = [];

	public function createContent ()
	{
		$this->mode = $this->uiRouter->urlPath[1];//$this->app->requestPath(3);

    $from = $this->app->testPostParam('from');
		$a = new \e10\users\libs\Authenticator($this->app());

		if ($this->mode === 'login')
		{
      $credentials = [
        'login' => $this->app->testPostParam('login'),
        'password' => $this->app->testPostParam('password'),
      ];

      $a = new \e10\users\libs\Authenticator($this->app());
      if ($a->checkUser($credentials))
      {
        $redirTo = str_replace('//', '/', $this->uiTemplate->data['uiRoot'].$from);
        header('Location: ' . $redirTo);
        die();
      }

      header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/login/'.$from.'?from=1');
			die();
		}

		if ($this->mode === 'pin')
		{
      $credentials = [
        'login' => $this->app->testPostParam('login'),
        'pin' => $this->app->testPostParam('pin'),
      ];

      $a = new \e10\users\libs\Authenticator($this->app());
      if ($a->checkUserPin($credentials))
      {
        $redirTo = str_replace('//', '/', $this->uiTemplate->data['uiRoot'].$from);
        header('Location: ' . $redirTo);
        die();
      }

      header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/login/'.$from.'?from=1');
			die();
		}

    if ($this->mode === 'logout')
		{
      $a->closeSession();
      header('Location: ' . $this->uiTemplate->data['uiRoot']);
    }

    if ($this->mode === 'robot')
		{
      $apiKey = $this->uiRouter->urlPath[2];
      $a = new \e10\users\libs\Authenticator($this->app());
      if ($a->checkRobot($apiKey))
      {
        $redirTo = str_replace('//', '/', $this->uiTemplate->data['uiRoot']);
        header('Location: ' . $redirTo);
        die();
      }

      header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/login/'.'?from=1');
			die();
		}

		if ($this->mode === 'activate')
		{
      $credentials = [
        'requestId' => $this->app->testPostParam('requestId'),
        'password1' => $this->app->testPostParam('password1'),
        'password2' => $this->app->testPostParam('password2'),
      ];

      $a = new \e10\users\libs\Authenticator($this->app());
      $msg = '';
      $res = $a->checkActivation($credentials, $msg);
      if ($res == 0)
      {
        header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/login');
        die();
      }
      header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/activate/'.$credentials['requestId'].'?from='.$res);
			die();
		}

		if ($this->mode === 'change-password')
		{
      $credentials = [
        'requestId' => $this->app->testPostParam('requestId'),
        'password1' => $this->app->testPostParam('password1'),
        'password2' => $this->app->testPostParam('password2'),
      ];

      $a = new \e10\users\libs\Authenticator($this->app());
      $msg = '';
      $res = $a->changePassword($credentials, $msg);
      if ($res == 0)
      {
        header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/login');
        die();
      }
      header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/change-password/'.$credentials['requestId'].'?from='.$res);
			die();
		}

		if ($this->mode === 'send-lost-password')
		{
      $credentials = [
        'userEmail' => $this->app->testPostParam('userEmail'),
      ];

      $a = new \e10\users\libs\Authenticator($this->app());
      $res = $a->sendLostPassword($credentials, $this->uiRouter);
      if ($res == 0)
      {
        header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/send-lost-password-done');
        die();
      }

      header('Location: ' . $this->uiTemplate->data['uiRoot'].'user/send-lost-password/'.$credentials['requestId'].'?from='.$res);
			die();
		}
  }
}


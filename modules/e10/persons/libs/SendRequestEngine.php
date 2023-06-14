<?php

namespace e10\persons\libs;
use \Shipard\Base\Utility, \Shipard\Utils\Json;
use \e10\base\libs\UtilsBase;


/**
 * class SendRequestEngine
 */
class SendRequestEngine extends Utility
{
	var $tableRequests;
	var $requestNdx = 0;
	var $requestRecData;
	var $requestData;

  var $forceEmailTo = '';

  CONST rqtUserSelfRegistration = 0, rqtLostPassword = 1, rqtFirstLogin = 2, rqtInvitationRequest = 3, rqtActivateShipardAccount = 4;


  public function setRequestNdx($requestNdx)
  {
		$this->tableRequests = $this->app()->table('e10.persons.requests');
		$this->requestNdx = $requestNdx;
		$this->requestRecData = $this->tableRequests->loadItem($this->requestNdx);
		$this->requestData = Json::decode($this->requestRecData['requestData']);
  }

  function sendRequest()
	{
    $type = $this->requestRecData['requestType'];
    $data = $this->requestData;
    $emailAddress = '';
		switch ($type)
		{
			case  self::rqtUserSelfRegistration:
				$emailAddress = $data['person']['login'];
				break;
			case  self::rqtActivateShipardAccount:
				$emailAddress = $data['person']['login'];
				break;
			case  self::rqtFirstLogin:
				$emailAddress = $data['login'];
				break;
			case  self::rqtLostPassword:
				$emailAddress = $data['login'];
				break;
		}

    if ($this->forceEmailTo !== '')
      $emailAddress = $this->forceEmailTo;

		$email = $this->createEmailForRequest($type, $data, $this->requestRecData['requestId']);

    UtilsBase::sendEmail($this->app, $email ['subject'], $email ['message'], $email ['fromEmail'], $emailAddress, $email ['fromName'], '');
	}

  public function createEmailForRequest($type, $data, $requestId)
	{
		$siteName = $this->app()->cfgItem('options.core.ownerShortName', '');
		$siteEmail = $this->app()->cfgItem('options.core.ownerEmail', '');
		$sitePhone = $this->app()->cfgItem('options.core.ownerPhone', '');
		$siteWeb = $this->app()->cfgItem('options.core.ownerWeb', '');

		$email = [];
		$email ['fromEmail'] = $siteEmail;
		$email ['fromName'] = 'Technická podpora ' . $siteName;

		$urlHost = $_SERVER['HTTP_HOST'];

		switch ($type)
		{
			case  self::rqtUserSelfRegistration:
				$email ['subject'] = 'Potvrzení registrace - ' . $siteName;
				$email ['message'] = "Dobrý den, \naby Vaše registrace na $siteName fungovala, klikněte prosím na následující odkaz:\n" .
						"{$this->app->urlProtocol}{$urlHost}{$this->app->dsRoot}/user/request/$requestId";
				break;
			case  self::rqtActivateShipardAccount:
				$email ['subject'] = "Potvrzení účtu na " . $data['dsName'];
				$email ['message'] = "Dobrý den, \naby Váš účet na {$data['dsName']} fungoval, klikněte prosím na následující odkaz:\n" .
						"{$this->app->urlProtocol}{$urlHost}{$this->app->dsRoot}/user/request/$requestId";
				break;
			case  self::rqtFirstLogin:
				$email ['subject'] = "Váš účet na " . $data['dsName'];
				$email ['message'] = "Dobrý den, \naby Váš účet na {$data['dsName']} fungoval, klikněte prosím na následující odkaz:\n" .
						"{$this->app->urlProtocol}{$urlHost}{$this->app->dsRoot}/user/request/$requestId";
				break;
			case  self::rqtLostPassword:
				$email ['subject'] = 'Žádost o nové heslo na ' . $siteName;
				$email ['message'] = "Dobrý den, \npro vytvoření nového hesla na $siteName klikněte prosím na následující odkaz:\n" .
						"{$this->app->urlProtocol}{$urlHost}{$this->app->dsRoot}/user/request/$requestId";
				break;
		} // switch ($type)

		$email ['message'] .= "\n--\n  email: $siteEmail | hotline: $sitePhone | $siteWeb \n\n";
		return $email;
	}

	public function requestUrl()
	{
		$urlHost = $_SERVER['HTTP_HOST'];
		$url = $this->app->urlProtocol.$urlHost.$this->app->dsRoot.'/user/request/'.$this->requestRecData['requestId'];
		return $url;
	}
}

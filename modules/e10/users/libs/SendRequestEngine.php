<?php

namespace e10\users\libs;
use \Shipard\Base\Utility, \Shipard\Utils\Utils;


/**
 * class SendRequestEngine
 */
class SendRequestEngine extends Utility
{
	var $tableRequests;
	var $requestNdx = 0;
	var $requestRecData;
  var $userRecData = NULL;
  var $uiRecData = NULL;
  var $requestTypeCfg = NULL;

  public function setRequestNdx($requestNdx)
  {
		$this->tableRequests = $this->app()->table('e10.users.requests');
		$this->requestNdx = $requestNdx;
		$this->requestRecData = $this->tableRequests->loadItem($this->requestNdx);
    $this->userRecData = $this->app()->loadItem($this->requestRecData['user'], 'e10.users.users');
    $this->uiRecData = $this->app()->loadItem($this->requestRecData['ui'], 'e10.ui.uis');
    $this->requestTypeCfg = $this->app()->cfgItem('e10.users.requestTypes.'.$this->requestRecData['requestType']);
  }

	public function requestUrl()
	{
    $url = '';
    $host = '';
    if ($this->uiRecData['domain'] !== '')
    {
      $host = $this->uiRecData['domain'];
    }
    else
    {
      $host = $_SERVER['HTTP_HOST'].'/ui/'.$this->uiRecData['urlId'];
    }

		$url = 'https://'.$host;
    $url .= '/user/'.$this->requestTypeCfg['urlPart'].'/'.$this->requestRecData['requestId'];
		return $url;
	}

	public function sendRequest ()
	{
		$emailsTo = $this->userRecData['email'];
		$report = new \e10\users\libs\reports\ReportRequestActivate($this->tableRequests, $this->requestRecData);

		$report->init();
		$report->renderReport ();
		$report->createReport ();
		$msgSubject = $report->createReportPart('emailSubject');
		$msgBody = $report->createReportPart('emailBody');

		$msg = new \Shipard\Report\MailMessage($this->app());

		$msg->setFrom ($this->app->cfgItem ('options.core.ownerFullName'), $this->app->cfgItem ('options.core.ownerEmail'));
		$msg->setTo($emailsTo);
		$msg->setSubject($msgSubject);
		$msg->setBody($msgBody);
		$msg->setDocument ('e10.users.requests', $this->requestNdx, $report);

		$attachmentFileName = Utils::safeChars($report->createReportPart ('fileName'));
		if ($attachmentFileName === '')
			$attachmentFileName = 'priloha';

		$msg->addAttachment($report->fullFileName, $attachmentFileName.'.pdf', 'application/pdf');

		$msg->sendMail();
		$msg->saveToOutbox();

		$report->reportWasSent($msg);
	}
}

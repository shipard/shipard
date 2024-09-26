<?php

namespace Shipard\Report;

use e10doc\templates\TableHeads;
use Shipard\Base\Utility;
use \Shipard\Utils\Utils, \Shipard\Report\MailMessage;


/**
 * class FormReportEngine
 */
class FormReportEngine extends Utility
{
  /** @var \Shipard\Report\FormReport */
  var $report = NULL;

  /** @var \Shipard\Table\DbTable */
  var $documentTable;

  /** @var \Shipard\Report\MailMessage */
  var $msg;

  var array $params = [];
  var ?array $documentInfo = NULL;

  public function init()
  {
  }

  public function setParam($key, $value)
  {
    $this->params[$key] = $value;
  }

	public function createReport ($prepareOnly = FALSE)
	{
		/** @var \e10\persons\TablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');

		$this->documentTable = $this->app()->table ($this->params['documentTable']);

		$this->report = $this->documentTable->getReportData ($this->params['reportClass'], $this->params['documentNdx']);

		$this->params['subject'] = $this->report->createReportPart ('emailSubject');
		$this->params['text'] = $this->report->createReportPart ('emailBody');
		$this->params['to'] = '';

		$item = $this->documentTable->loadItem ($this->params['documentNdx']);
		$this->documentInfo = $this->documentTable->getRecordInfo ($item);
		if (isset($this->documentInfo['persons']['to']))
		{
			$this->params['to'] = $tablePersons->loadEmailsForReport($this->documentInfo['persons']['to'], $this->params['reportClass']);
		}
		$this->params['emailFromAddress'] = $this->app->cfgItem ('options.core.ownerEmail');
		$this->params['emailFromName'] = $this->app->cfgItem ('options.core.ownerFullName');
		if (isset($this->documentInfo['emailFromAddress']))
		{
			$this->params['emailFromAddress'] = $this->documentInfo['emailFromAddress'];
			$this->params['emailFromName'] = $this->documentInfo['emailFromName'];
		}

    if ($prepareOnly)
      return;

		$this->report->renderReport ();
		$this->report->createReport ();
	}

	public function createMsg ()
	{
		$this->msg = new MailMessage($this->app());

		$this->msg->setFrom ($this->params['emailFromName'], $this->params['emailFromAddress']);
		$this->msg->setTo($this->params['to']);
		$this->msg->setSubject($this->params['subject']);
		$this->msg->setBody($this->params['text']);
		$this->msg->setDocument ($this->params['documentTable'], $this->params['documentNdx'], $this->report);

		$attachmentFileName = utils::safeChars($this->report->createReportPart ('fileName'));
		if ($attachmentFileName === '')
			$attachmentFileName = 'priloha';

		$this->msg->addAttachment($this->report->fullFileName, $attachmentFileName.'.pdf', 'application/pdf');

		//$this->addOtherReports($documentTable, $msg, $report);
	}

  public function sendMsg($sendMode)
  {
    if ($sendMode === TableHeads::asmFullSend)
		  $this->msg->sendMail();

		$this->msg->saveToOutbox();
  }
}


<?php

namespace e10doc\balance;
use \Shipard\Base\DocumentAction, \Shipard\Report\MailMessage, \Shipard\Utils\Utils;


/**
 * Class RequestForPaymentAction
 */
class RequestForPaymentAction extends DocumentAction
{
	public function init ()
	{
		parent::init();
	}

	public function actionName ()
	{
		return 'Rozeslat upomínky';
	}

	/*
	public function actionParams()
	{
		$params = [
				['name' => 'Pouze testovací emaily', 'id' => 'test', 'type' => 'checkbox', 'checked' => 1]
		];

		return $params;
	}
	*/

	public function sendOne ($personNdx)
	{
		$emailsTo = $this->loadEmails($personNdx);
		if ($emailsTo === '')
			return;

		$documentTable = $this->app()->table ('e10.persons.persons');

		$person = $documentTable->loadItem ($personNdx);

		$report = new \e10doc\balance\RequestForPayment($documentTable, $person);
		$report->init();
		$report->renderReport ();
		$report->createReport ();
		$msgSubject = $report->createReportPart('emailSubject');
		$msgBody = $report->createReportPart('emailBody');

		$msg = new MailMessage($this->app());

		$msg->setFrom ($this->app->cfgItem ('options.core.ownerFullName'), $this->app->cfgItem ('options.core.ownerEmail'));
		$msg->setTo($emailsTo);
		$msg->setSubject($msgSubject);
		$msg->setBody($msgBody);
		$msg->setDocument ('e10.persons.persons', $personNdx, $report);

		$attachmentFileName = Utils::safeChars($report->createReportPart ('fileName'));
		if ($attachmentFileName === '')
			$attachmentFileName = 'priloha';

		$msg->addAttachment($report->fullFileName, $attachmentFileName.'.pdf', 'application/pdf');
		$report->addMessageAttachments($msg);

		$msg->sendMail();
		$msg->saveToOutbox();
	}

	public function run ()
	{
		$report = new \e10doc\balance\ReportRequestsForPayment ($this->app());
		$report->createPdf();

		foreach ($report->persons as $personNdx)
		{
			$this->sendOne($personNdx);
		}
	}

	public function loadEmails ($personNdx)
	{
		$sql = 'SELECT valueString FROM [e10_base_properties] where [tableid] = %s AND [recid] = %i AND [property] = %s AND [group] = %s ORDER BY ndx';
		$emailsRows = $this->db()->query ($sql, 'e10.persons.persons', $personNdx, 'email', 'contacts')->fetchPairs ();
		return implode (', ', $emailsRows);
	}
}

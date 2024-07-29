<?php

namespace e10pro\soci\libs;
use \Shipard\Base\Utility;


/**
 * class SendEntriesEmails
 */
class SendEntriesEmails extends Utility
{
  protected function sendOne($recData)
  {
    $emails = [];
    if ($recData['email'] !== '')
      $emails [] = $recData['email'];
    if (!count($emails))
      return;

    $documentTable = $this->app()->table ('e10pro.soci.entries');
		$report = $documentTable->getReportData ('e10pro.soci.libs.ReportEntry', $recData['ndx']);
		$report->renderReport ();
		$report->createReport ();

    $emailTo = implode(', ', $emails);

		$msg = new \Shipard\Report\MailMessage($this->app());

		$emailSubject = $report->createReportPart ('emailSubject');
		$emailBody = $report->createReportPart ('emailBody');

		$msg->setFrom ($this->app->cfgItem ('options.core.ownerFullName'), $this->app->cfgItem ('options.core.ownerEmail'));
		$msg->setTo($emailTo);
		$msg->setSubject($emailSubject);
		$msg->setBody($emailBody);
		$msg->setDocument ('e10pro.soci.entries', $recData['ndx'], $report);

    $attachmentFileName = 'potvrzeni-prihlasky';

		$msg->addAttachment($report->fullFileName, $attachmentFileName.'.pdf', 'application/pdf');

		$msg->sendMail();
		$msg->saveToOutbox();

    $this->db()->query('UPDATE [e10pro_soci_entries] SET [confirmEmailDone] = %i', 1, ' WHERE [ndx] = %i', $recData['ndx']);
  }

  public function sendAll()
  {
    $q = [];
    array_push ($q, 'SELECT * FROM [e10pro_soci_entries]');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [confirmEmailDone] = %i', 0);
    array_push ($q, ' AND [source] = %i', 2);
    array_push ($q, ' AND [docState] = %i', 1000);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->sendOne($r->toArray());
    }
  }
}


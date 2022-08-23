<?php

namespace e10pro\zus\libs;
use E10Pro\Zus\zusutils, \e10\Utility, \e10\utils;


class SendEntriesEmails extends Utility
{
  protected function sendOne($recData)
  {
    $emails = [];
    if ($recData['emailM'] !== '')
      $emails [] = $recData['emailM'];
    if ($recData['emailF'] !== '' && $recData['emailF'] !== $recData['emailM'])
      $emails [] = $recData['emailF'];
    if (!count($emails))
      return;

    $documentTable = $this->app()->table ('e10pro.zus.prihlasky');
		$report = $documentTable->getReportData ('e10pro.zus.libs.ReportPrihlaska', $recData['ndx']);
		$report->renderReport ();
		$report->createReport ();

    $emailTo = implode(', ', $emails);

		$msg = new \e10\MailMessage($this->app());

		$emailSubject = $report->createReportPart ('emailSubject');
		$emailBody = $report->createReportPart ('emailBody');

		$msg->setFrom ($this->app->cfgItem ('options.core.ownerFullName'), $this->app->cfgItem ('options.core.ownerEmail'));
		$msg->setTo($emailTo);
		$msg->setSubject($emailSubject);
		$msg->setBody($emailBody);
		$msg->setDocument ('e10pro.zus.prihlasky', $recData['ndx'], $report);

//		$attachmentFileName = utils::safeChars($report->createReportPart ('fileName'));
//		if ($attachmentFileName === '')
			$attachmentFileName = 'potvrzeni-prihlasky';

		$msg->addAttachment($report->fullFileName, $attachmentFileName.'.pdf', 'application/pdf');

		$msg->sendMail();
		$msg->saveToOutbox();

    $this->db()->query('UPDATE [e10pro_zus_prihlasky] SET [confirmEmailDone] = %i', 1, ' WHERE [ndx] = %i', $recData['ndx']);
  }

  public function sendAll()
  {
    $q = [];
    array_push ($q, 'SELECT * FROM [e10pro_zus_prihlasky]');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [confirmEmailDone] = %i', 0);
    array_push ($q, ' AND [docState] = %i', 1200);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->sendOne($r->toArray());
    }
  }
}
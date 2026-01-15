<?php

namespace e10doc\waster\libs;
use \Shipard\Base\Utility;


/**
 * class SendCompaniesReportsEngine
 */
class SendCompaniesReportsEngine extends Utility
{
  var $maxCount = 25;
  var $forceEmailTo = '';
  var $wasteReturnNdx = 0;

  protected function sendOne($recData)
  {
    $reportClassId = ($recData['dir'] === 0) ? 'e10doc.waster.libs.ReportCompaniesReportIn' : 'e10doc.waster.libs.ReportCompaniesReportOut';
    /** @var \e10\persons\TablePersons */
    $tablePersons = $this->app()->table ('e10.persons.persons');

		$emailsTo = $tablePersons->loadEmailsForReport([$recData['companyPerson']], $reportClassId);
    if ($emailsTo === '')
      return;

    if ($this->forceEmailTo !== '')
      $emailsTo = $this->forceEmailTo;

    if ($this->app()->debug)
      echo '#'.$recData['ndx'].'; '.$recData['personFullName'].'; EMAILS: '.$emailsTo."\n";

    /** @var \e10doc\waster\TableCompaniesReports */
    $documentTable = $this->app()->table ('e10doc.waster.companiesReports');
    /** @var \e10doc\waster\libs\ReportCompaniesReportIn */
		$report = $documentTable->getReportData ($reportClassId, $recData['ndx']);
		$report->renderReport ();
		$report->createReport ();

		$msg = new \Shipard\Report\MailMessage($this->app());

		$emailSubject = $report->createReportPart ('emailSubject');
		$emailBody = $report->createReportPart ('emailBody');

		$msg->setFrom ($this->app->cfgItem ('options.core.ownerFullName'), $this->app->cfgItem ('options.core.ownerEmail'));

		$msg->setTo($emailsTo);
		$msg->setSubject($emailSubject);
		$msg->setBody($emailBody);
		$msg->setDocument ('e10doc.waster.companiesReports', $recData['ndx'], $report);

    $attachmentFileName = $report->createReportPart ('fileName');

		$msg->addAttachment($report->fullFileName, $attachmentFileName.'.pdf', 'application/pdf');
    $report->addMessageAttachments($msg);

		$msg->send();
    $report->reportWasSent($msg);
  }

  public function sendAll()
  {
    $q = [];
    array_push ($q, 'SELECT [cr].*, [persons].fullName AS personFullName');
    array_push ($q, ' FROM e10doc_waster_companiesReports AS [cr]');
    array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON [cr].companyPerson = persons.ndx');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [cr].[sentState] = %i', 0);
    array_push ($q, ' AND [cr].[docState] = %i', 4000);

    if ($this->wasteReturnNdx)
      array_push ($q, ' AND [cr].[wasteReturn] = %i', $this->wasteReturnNdx);
    array_push ($q, ' ORDER BY [cr].[ndx]');
    array_push ($q, ' LIMIT %i', ($this->maxCount + 1));

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->sendOne($r->toArray());
    }
  }
}

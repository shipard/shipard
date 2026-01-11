<?php

namespace e10doc\waster\libs;
use \Shipard\Base\Utility;


/**
 * class SendMuniReportsEngine
 */
class SendMuniReportsEngine extends Utility
{
  var $maxCount = 25;
  var $forceGovBoxId = '';
  var $wasteReturnNdx = 0;

  protected function sendOne($recData)
  {
    /** @var \e10\persons\TablePersons */
    $tablePersons = $this->app()->table ('e10.persons.persons');
    $govBoxes = $tablePersons->loadGovBoxes([$recData['muniPerson']]);
    if (count($govBoxes) === 0)
      return;

    if ($this->forceGovBoxId !== '')
    {
      $govBoxes = ['$'.$this->forceGovBoxId];
    }

    if ($this->app()->debug)
      echo '#'.$recData['ndx'].'; '.$recData['personFullName'].'; GOVBOX: '.implode(', ', $govBoxes)."\n";

    /** @var \e10doc\waster\TableMuniReports */
    $documentTable = $this->app()->table ('e10doc.waster.muniReports');
    /** @var \e10doc\waster\libs\ReportMuniReport */
		$report = $documentTable->getReportData ('e10doc.waster.libs.ReportMuniReport', $recData['ndx']);
		$report->renderReport ();
		$report->createReport ();

    $govBoxesTo = implode(', ', $govBoxes);

		$msg = new \Shipard\Report\MailMessage($this->app());

		$emailSubject = $report->createReportPart ('emailSubject');
		$emailBody = $report->createReportPart ('emailBody');

		$msg->setFrom ($this->app->cfgItem ('options.core.ownerFullName'), $this->app->cfgItem ('options.core.ownerEmail'));

		$msg->setTo($govBoxesTo);
		$msg->setSubject($emailSubject);
		$msg->setBody($emailBody);
		$msg->setDocument ('e10doc.waster.muniReports', $recData['ndx'], $report);

    $attachmentFileName = $report->createReportPart ('fileName');

		$msg->addAttachment($report->fullFileName, $attachmentFileName.'.pdf', 'application/pdf');

		$msg->send();
    $report->reportWasSent($msg);
  }

  public function sendAll()
  {
    $q = [];
    array_push ($q, 'SELECT [mr].*, [persons].fullName AS personFullName');
    array_push ($q, ' FROM e10doc_waster_muniReports AS [mr]');
    array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON [mr].muniPerson = persons.ndx');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [mr].[sentState] = %i', 0);
    array_push ($q, ' AND [mr].[docState] = %i', 4000);
    if ($this->wasteReturnNdx)
      array_push ($q, ' AND [mr].[wasteReturn] = %i', $this->wasteReturnNdx);
    array_push ($q, ' ORDER BY [mr].[ndx]');
    array_push ($q, ' LIMIT %i', ($this->maxCount + 1));

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->sendOne($r->toArray());
    }
  }
}

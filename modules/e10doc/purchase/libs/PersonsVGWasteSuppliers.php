<?php

namespace e10doc\purchase\libs;
use \Shipard\Utils\Utils;


/**
 * class PersonsVGWasteSuppliers
 */
class PersonsVGWasteSuppliers extends \lib\persons\PersonsVirtualGroup
{
	public function enumItems($columnId, $recData)
	{
		if ($columnId === 'virtualGroupItem')
    {
      $today = Utils::today();
      $year = intval($today->format('Y'));

      $enum = [];
      for ($i = $year - 1; $i <= $year; $i++)
        $enum[$i] = strval($i);
      return $enum;
    }

		return [];
	}

	public function addPosts($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $vgRecData)
	{
    $report = new \e10pro\reports\waste_cz\libs\ReportWasteCompanies($this->app());
    $report->subReportId = 'companiesIn';
    $report->calendarYear = intval($vgRecData['virtualGroupItem']);
    $report->periodBegin = $report->calendarYear.'-01-01';
    $report->periodEnd = $report->calendarYear.'-12-31';
    $report->createPdf();

    foreach ($report->persons as $personNdx)
    {
			if ($this->recipientsMode === self::rmPersonsMsgs)
			{
				$this->addRecipientPerson ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $personNdx);
				continue;
			}

      $emails = $this->personsEmails($personNdx);
      foreach ($emails as $email)
      {
        $this->addPostEmail ($dstTable, $bulkOwnerColumnId, $bulkOwnerNdx, $personNdx, $email);
      }
    }
	}
}

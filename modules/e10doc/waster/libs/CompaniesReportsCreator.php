<?php

namespace e10doc\waster\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils;


/**
 * Class CompaniesReportsCreator
 */
class CompaniesReportsCreator extends Utility
{
  var $wasteDir = 0; // 0 - In, 1 - Out

  public function createAll($wasteReturnNdx)
  {
    if ($this->app()->debug)
      echo "Creating companies reports for waste return #$wasteReturnNdx...\n";

    $wasteReturnRecData = $this->app()->loadItem($wasteReturnNdx, 'e10doc.waster.wasteReturns');
    if (!$wasteReturnRecData)
    {
      error_log("ERROR: waste return #$wasteReturnNdx not found!\n");
      return;
    }

    $year = $wasteReturnRecData['year'];

    $dateBegin = Utils::createDateTime("$year-01-01");
    $dateEnd = Utils::createDateTime("$year-12-31");
    $codeKindNdx = 1;

    //$this->db()->query('DELETE FROM e10doc_waster_companiesReports WHERE wasteReturn = %i', $wasteReturnNdx, ' AND dir = %i', $this->wasteDir);

    $q = [];
    array_push ($q, 'SELECT persons.*');
    array_push ($q, ' FROM [e10_persons_persons] AS persons');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND persons.company = 1');
    array_push ($q, ' AND EXISTS (SELECT DISTINCT person FROM e10pro_reports_waste_cz_returnRows WHERE persons.ndx = e10pro_reports_waste_cz_returnRows.person ',
                                ' AND [calendarYear] = %i', $wasteReturnRecData['year'],
                                ' AND e10pro_reports_waste_cz_returnRows.[dir] = %i', $this->wasteDir,
                                ' AND e10pro_reports_waste_cz_returnRows.[wasteCodeKind] = %i', $codeKindNdx,
                                ')');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      // -- existing?
      $q = [];
      array_push($q, 'SELECT [cr].*');
      array_push($q, ' FROM [e10doc_waster_companiesReports] AS [cr]');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [companyPerson] = %i', $r['ndx']);
      array_push($q, ' AND [wasteReturn] = %i', $wasteReturnNdx);
      array_push($q, ' AND [dir] = %i', $this->wasteDir);
      array_push($q, ' LIMIT 1');
      $existingCR = $this->db()->query($q)->fetch();

      if ($existingCR)
      { // UPDATE
        //continue;
      }
      else
      { // -- create new
        $newCR = [
          'dir' => $this->wasteDir,
          'companyPerson' => $r['ndx'],
          'wasteReturn' => $wasteReturnNdx,
          'docState' => 4000, 'docStateMain' => 2,
        ];

        $this->db()->query('INSERT INTO [e10doc_waster_companiesReports]', $newCR);
      }
    }
  }
}

<?php

namespace e10pro\loyp\libs;
use \Shipard\Utils\Utils;


/**
 * class AnalyzePointsEngine
 */
class AnalyzePointsEngine extends \Shipard\Base\Utility
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;

  var $overviewData = [];
  var $overviewTable = [];
  var $overviewHeader = [];
  var $overviewContent = NULL;

  var $hstTable = [];
  var $hstHeader = [];
  var $hstContent = NULL;


  public function setPeriod($dateBegin, $dateEnd)
  {
    $this->periodBegin = Utils::createDateTime($dateBegin);
    $this->periodEnd = Utils::createDateTime($dateEnd);
  }

  public function loadOverview()
  {
    $this->loadOverviewPart(1, 'Občané');
    $this->loadOverviewPart(2, 'Firmy');

    $this->overviewHeader = ['title' => '', 'cnt' => '+Počet zákazníků', 'points' => '+Celkem bodů', 'minPoints' => ' Minimum bodů', 'maxPoints' => ' Maximum bodů'];
    $this->overviewContent = ['table' => $this->overviewTable, 'header' => $this->overviewHeader];

    $this->loadOverviewHistogram(1, 'Občané');
    $this->loadOverviewHistogram(2, 'Firmy');
    $this->hstHeader = ['#' => '#', 'from' => ' Body od', 'to' => ' Body do', 'cnt' => '+Počet zákazníků'];
    $this->hstContent[1] = ['table' => $this->hstTable[1], 'header' => $this->hstHeader, 'title' => 'Občané'];
    $this->hstContent[2] = ['table' => $this->hstTable[2], 'header' => $this->hstHeader, 'title' => 'Firmy'];
  }

  protected function loadOverviewPart($personType, $title)
  {
    $q = [];
    array_push ($q, 'SELECT SUM(cntPoints) AS sumCntPoints');
    array_push ($q, ' FROM [e10pro_loyp_pointsJournal] AS [journal]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [journal].person = [persons].ndx');
		array_push ($q, ' WHERE 1');
	  array_push ($q, ' AND ([persons].[personType] = %i)', $personType);
    $r = $this->db()->query($q)->fetch();
    $tableRow = ['title' => $title, 'points' => $r['sumCntPoints'],];
    $this->overviewData[$personType] = ['points' => $r['sumCntPoints'],];


    $q = [];
    array_push ($q, 'SELECT MIN(sumCntPoints) AS minCntPoints, MAX(sumCntPoints) AS maxCntPoints FROM (');
    array_push ($q, 'SELECT person, SUM(cntPoints) AS sumCntPoints');
    array_push ($q, ' FROM [e10pro_loyp_pointsJournal] AS [journal]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [journal].person = [persons].ndx');
		array_push ($q, ' WHERE 1');
	  array_push ($q, ' AND ([persons].[personType] = %i)', $personType);
    array_push ($q, ' GROUP BY 1');
    array_push ($q, ') mm');
    $r = $this->db()->query($q)->fetch();
    $tableRow['minPoints'] = $r['minCntPoints'];
    $tableRow['maxPoints'] = $r['maxCntPoints'];
    $this->overviewData[$personType]['minPoints'] = $r['minCntPoints'];
    $this->overviewData[$personType]['maxPoints'] = $r['maxCntPoints'];

    $q = [];
    array_push ($q, 'SELECT COUNT(DISTINCT(person)) AS cntRows');
    array_push ($q, ' FROM [e10pro_loyp_pointsJournal] AS [journal]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [journal].person = [persons].ndx');
		array_push ($q, ' WHERE 1');
	  array_push ($q, ' AND ([persons].[personType] = %i)', $personType);
    $r = $this->db()->query($q)->fetch();
    $tableRow['cnt'] = $r['cntRows'];

    $this->overviewTable[] = $tableRow;
  }

  protected function loadOverviewHistogram($personType, $title)
  {
    $cntBlocks = 50;
    $step = intval($this->overviewData[$personType]['maxPoints'] / $cntBlocks);
    $from = 0;
    $to = 0;
    $stepNum = 0;
    while(1)
    {
      $q = [];
      array_push ($q, 'SELECT COUNT(DISTINCT(person)) AS cntRows ');
      array_push ($q, ' FROM [e10pro_loyp_pointsJournal] AS [journal2]');
      array_push ($q, ' WHERE person IN (');
      array_push ($q, 'SELECT person');
      array_push ($q, ' FROM [e10pro_loyp_pointsJournal] AS [journal]');
      array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [journal].person = [persons].ndx');
      array_push ($q, ' WHERE 1');
      array_push ($q, ' AND ([persons].[personType] = %i)', $personType);
      array_push ($q, ' GROUP BY 1');
      array_push ($q, ' HAVING SUM(cntPoints) BETWEEN %i AND %i', $from, $to);
      array_push ($q, ' )');
      $r = $this->db()->query($q)->fetch();
      //error_log("####".\dibi::$sql);
      //if (!$r)
      //  continue;

      $rowItem = ['from' => $from, 'to' => $to, 'cnt' => $r['cntRows'] ?? 0];
      $this->hstTable[$personType][] = $rowItem;

      $from = $to + 1;
      /*
      if ($stepNum < 10)
        $to = $from + 5;
      elseif ($stepNum < 30)
        $to = $from + 10;
      else
        $to = $from + $step;
      */
      $to = $from + 20;
      if ($from > $this->overviewData[$personType]['maxPoints'])
        break;

      $stepNum++;
    }
  }

  public function run()
  {
    $this->loadOverview();
  }
}

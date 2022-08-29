<?php

namespace e10doc\reporting\libs;
use \Shipard\Utils\Json;


/**
 * class CalcReportEngine
 */
class CalcReportEngine extends \Shipard\Base\Utility
{
  var $calcReportNdx = 0;
  var $calcReportRecData = NULL;
  var $srcHeaderData = NULL;


  /** @var \e10doc\reporting\TableCalcReports */
  var $tableCalcReports;

  /** @var \e10doc\reporting\TableCalcReportsRowsSD */
  var $tableCalcReportsRowsSD;


  var \Shipard\Utils\Numbers $numbers;


  public function setCalcReport(int $calcReportNdx)
  {
    $this->calcReportNdx = $calcReportNdx;

    $this->tableCalcReports = $this->app()->table('e10doc.reporting.calcReports');
    $this->calcReportRecData = $this->tableCalcReports->loadItem($this->calcReportNdx);
    $this->srcHeaderData = Json::decode ($this->calcReportRecData['srcHeaderData']);
    if (!$this->srcHeaderData)
      $this->srcHeaderData = [];

    $this->tableCalcReportsRowsSD = $this->app()->table('e10doc.reporting.calcReportsRowsSD');


    $this->numbers = new \Shipard\Utils\Numbers($this->app());
  }

  public function doRebuild()
  {
  }
}

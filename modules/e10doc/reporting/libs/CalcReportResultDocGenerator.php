<?php

namespace e10doc\reporting\libs;
use \Shipard\Utils\Json;


/**
 * class CalcReportResultDocGenerator
 */
class CalcReportResultDocGenerator extends \Shipard\Base\Utility
{
  var $calcReportResultNdx = 0;
  var $calcReportResultRecData = NULL;
  var $calcReportResultResultData = NULL;

  var $calcReportNdx = 0;
  var $calcReportRecData = NULL;

  var $calcReportCfgRecData = NULL;
  var $calcReportCfgSettings = NULL;


  public function setCalcReportResult($calcReportResultNdx)
  {
    $this->calcReportResultNdx = $calcReportResultNdx;
    $this->calcReportResultRecData = $this->app()->loadItem($this->calcReportResultNdx, 'e10doc.reporting.calcReportsResults');

    $this->calcReportNdx = $this->calcReportResultRecData['report'];
    $this->calcReportRecData = $this->app()->loadItem($this->calcReportNdx, 'e10doc.reporting.calcReports');

    $this->calcReportCfgRecData = $this->app()->loadItem($this->calcReportRecData['calcReportCfg'], 'e10doc.reporting.calcReportsCfgs');
    $this->calcReportCfgSettings = Json::decode($this->calcReportCfgRecData['settings']);
    if (!$this->calcReportCfgSettings)
      $this->calcReportCfgSettings = [];

    $this->calcReportResultResultData = Json::decode($this->calcReportResultRecData['resData']);
  }

  public function generateDoc()
  {
  }
}

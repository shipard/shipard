<?php

namespace lib\rasterPrint;
use \Shipard\Base\Utility, e10\utils;


/**
 * class RasterPrinterEngine
 */
class RasterPrinterEngine extends Utility
{
  var $printerDriverCfg = NULL;
  var $labelsCfg = NULL;

  public function setCfg($printerDriverCfg, $labelsCfg)
  {
    $this->printerDriverCfg = $printerDriverCfg;
    $this->labelsCfg = $labelsCfg;
  }

  public function createPrinterData($srcFileName, $dstFileName)
  {
  }
}


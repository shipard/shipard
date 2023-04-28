<?php

namespace lib\rasterPrint;
use \Shipard\Base\Utility;


/**
 * class RPEBrotherQL
 */
class RPEBrotherQL extends \lib\rasterPrint\RasterPrinterEngine
{
  public function createPrinterData($srcFileName, $dstFileName)
  {
    $cmd = '/usr/local/bin/brother_ql_create';
    $cmd .= ' -m QL-820NWB';

    $labelId = '62';
    if (isset($this->labelsCfg['lpid']) && $this->labelsCfg['lpid'] !== '')
      $labelId = $this->labelsCfg['lpid'];

    $cmd .= ' --label-size '.$labelId;

    $cmd .= ' '.$srcFileName.' '.$dstFileName;
    exec($cmd);
  }
}


<?php

namespace services\locAddr\libs\imports;
use \Shipard\Base\Utility;

/**
 * class ImportEngineCore
 */
class ImportEngineCore extends Utility
{
  protected function downloadFile($url, $baseName)
  {
    $bn = basename($baseName);
    $cmd = 'wget -q -O '.$baseName.' '.$url;
    exec($cmd);
    $cmd = 'unzip '.$baseName;
    exec($cmd);
  }
}

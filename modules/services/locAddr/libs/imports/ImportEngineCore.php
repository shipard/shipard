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
    echo " - $url";
    $bn = basename($baseName);
    $cmd = 'wget -q -O '.$baseName.' "'.$url.'"';
    exec($cmd);

    if (str_ends_with($bn, '.zip'))
    {
      echo " -> unzip to $bn";
      $cmd = 'unzip '.$baseName;
      exec($cmd);
    }
    else
      echo " -> $bn";
    echo "\n";
  }
}

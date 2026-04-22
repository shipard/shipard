<?php

namespace mac\iot\libs;
use \Shipard\Base\Utility;



/**
 * class EPDImageConverter
 */
class EPDImageConverter extends Utility
{
  var $displayInfo = NULL;
  var $srcFileName = '';
  var $convertedFileName = '';

  public function setSrcFileName($srcFileName)
  {
    $this->srcFileName = $srcFileName;
  }

  public function setConvertedFileName($convertedFileName)
  {
    $this->convertedFileName = $convertedFileName;
  }

  public function setDisplayInfo($displayInfo)
  {
    $this->displayInfo = $displayInfo;
  }

  public function convertImage()
  {
    $didderPalette = implode(' ', $this->displayInfo['colorsDither']);
    //$cmd = "didder --palette \"{$didderPalette}\" -i ".$this->srcFileName." -o ".$this->convertedFileName; // -dither FloydSteinberg -define dither:diffusion-amount=85%
    //$cmd .= " -s 0.1 bayer 16x16";

    $cmd = "didder --palette \"{$didderPalette}\" -i ".$this->srcFileName." -o ".$this->convertedFileName;
    //$cmd .= " --brightness 0.15";
    //$cmd .= " --contrast 0.4";

    $cmd .= " edm FloydSteinberg";
    //$cmd .= " -s -1 odm ClusteredDotSpiral5x5";
    //$cmd .= " -s 0 bayer 32x32";

      //$cmd = "cp ".$this->srcFileName." ".$this->convertedFileName;
    exec ($cmd);

    // https://github.com/Utzel-Butzel/epdoptimize
  }
}


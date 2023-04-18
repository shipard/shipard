<?php

namespace e10doc\ddf\ddm\formatsEngines;
use \Shipard\Base\Utility;

use \e10\json, e10\utils;
use \e10doc\core\libs\E10Utils;

class CoreFE extends Utility
{
  var $srcText = '';
  var $srcTextRows;

  var $docRows = [];

  public function setSrcText($srcText)
  {
    $this->srcText = $srcText;
    $this->srcTextRows = preg_split("/\\r\\n|\\r|\\n/", $this->srcText);
  }

  public function import(&$coreHeadData)
  {
  }


}

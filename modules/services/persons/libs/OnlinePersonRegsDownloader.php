<?php

namespace services\persons\libs;
use \Shipard\Base\Utility;

/** 
 * class OnlinePersonRegsDownloader
 */
class OnlinePersonRegsDownloader extends \services\persons\libs\CoreObject
{
	var $personNdx = '';
  var $debug = 0;
  var $cntUpdates = 0;
  var $srcData = [];

  var ?\services\persons\libs\PersonData $personData = NULL;

  public function setPersonNdx($personNdx)
  {
    $this->personNdx = $personNdx;
  }

  public function run()
  {
    $this->personData = new \services\persons\libs\PersonData($this->app());
    $this->personData->setPersonNdx($this->personNdx);
    $this->personData->load();
  }
}

<?php

namespace services\persons\libs;
use \Shipard\Base\Utility;

/** 
 * class OnlinePersonRegsDownloader
 */
class OnlinePersonRegsDownloader extends Utility
{
  CONST prtCZAresInit = 1, prtCZRZPInit = 2, prtCZAresCore = 3, prtCZAresRZP = 4, prtCZRZP = 5, prtCZVAT = 6;

	var $countryId = '';
	var $personId = '';
  var $debug = 0;

  var $cntUpdates = 0;

  var $srcData = [];

  var ?\services\persons\libs\PersonData $personData = NULL;

  public function setPersonId($countryId, $personId)
  {
    $this->countryId = $countryId;
    $this->personId = $personId;
  }

  public function run()
  {
    $this->personData = new \services\persons\libs\PersonData($this->app());
    $this->personData->setPersonId($this->countryId, $this->personId);
    $this->personData->load();
  }
}

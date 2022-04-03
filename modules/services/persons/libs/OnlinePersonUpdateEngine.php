<?php

namespace services\persons\libs;
use \Shipard\Base\Utility;

class OnlinePersonUpdateEngine extends Utility
{
  var $countryId = '';
  var $personId = '';

  public function setPersonId($countryId, $personId)
  {
    $this->personId = $personId;
    $this->countryId = $countryId;
  }

  public function run()
  {
    $e = new \services\persons\libs\cz\OnlinePersonRegsDownloaderCZ($this->app());
    $e->setPersonId($this->countryId, $this->personId);
    $e->run();
  }
}


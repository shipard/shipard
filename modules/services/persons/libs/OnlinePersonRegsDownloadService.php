<?php

namespace services\persons\libs;
use \Shipard\Base\Utility;

final class OnlinePersonRegsDownloadService extends Utility
{
  var $countryId = '';
  var $personId = '';

  var $timeStart = 0;
  var $maxDuration = 120;

  var $debug = 0;

  public function setPersonId($countryId, $personId)
  {
    $this->personId = $personId;
    $this->countryId = $countryId;
  }

  public function downloadOnePerson()
  {
    $e = new \services\persons\libs\cz\OnlinePersonRegsDownloaderCZ($this->app());
    $e->setPersonId($this->countryId, $this->personId);
    $e->run();
  }

  public function downloadBlock()
  {
    $startTime = time();
    $now = new \DateTime();
    if ($this->debug)
      echo $now->format('Y-m-d H:i:s')."\n";
    while (1)
    {
      $q = [];
      array_push($q, 'SELECT * FROM [services_persons_persons]');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [importState] = %i', 0);
      array_push($q, ' AND [newDataAvailable] = %i', 0);
      array_push($q, ' AND [valid] = %i', 1);
      array_push($q, ' LIMIT 10');
      $rows = $this->db()->query($q);
      foreach ($rows as $r)
      {
        if ($this->debug)
          echo "# ".$r['oid'].': '.$r['fullName']."\n";
        $this->setPersonId('cz', $r['oid']);
        $this->downloadOnePerson();
      }

      $runLen = time() - $startTime;
      if ($this->debug)
        echo ' >>> '.$runLen.' secs (max is '.$this->maxDuration.')'."\n";
      
      if ($runLen > $this->maxDuration)
        break;
    }
    $now = new \DateTime();
    if ($this->debug)
      echo $now->format('Y-m-d H:i:s')."\n";
  }
}


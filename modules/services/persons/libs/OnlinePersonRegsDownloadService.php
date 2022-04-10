<?php

namespace services\persons\libs;
use \Shipard\Base\Utility;

final class OnlinePersonRegsDownloadService extends Utility
{
  var $personNdx = 0;

  var $timeStart = 0;
  var $maxDuration = 120;

  var $debug = 0;

  public function setPersonNdx($personNdx)
  {
    $this->personNdx = $personNdx;
  }

  public function downloadOnePerson()
  {
    $e = new \services\persons\libs\cz\OnlinePersonRegsDownloaderCZ($this->app());
    $e->setPersonNdx($this->personNdx);
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
      array_push($q, ' ORDER BY [iid]');
      array_push($q, ' LIMIT 10');
      $rows = $this->db()->query($q);
      foreach ($rows as $r)
      {
        if ($this->debug)
          echo "# ".$r['oid'].': '.$r['fullName']."\n";
        $this->setPersonNdx($r['ndx']);
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


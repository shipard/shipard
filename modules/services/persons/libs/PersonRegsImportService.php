<?php

namespace services\persons\libs;
use \Shipard\Base\Utility;

final class PersonRegsImportService extends Utility
{
  var $personNdx = 0;

  var $timeStart = 0;
  var $maxDuration = 120;

  var $debug = 0;

  public function importOnePerson()
  {
    if ($this->app()->debug)
      echo "* importOnePerson\n";
    $e = new \services\persons\libs\cz\ImportPersonFromRegsCZ($this->app());
    $e->setPersonNdx($this->personNdx);
    $e->run();
  }

  public function importBlock()
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
      array_push($q, ' AND [newDataAvailable] = %i', 2);
      array_push($q, ' AND [valid] = %i', 1);
      array_push($q, ' LIMIT 10');
      $rows = $this->db()->query($q);

      $cnt = 0;
      foreach ($rows as $r)
      {
        if ($this->debug)
          echo "# ".$r['oid'].': '.$r['fullName']."\n";
        $this->personNdx = $r['ndx'];
        $this->importOnePerson();

        $cnt++;
      }

      if (!$cnt)
        break;

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


<?php
namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';


use \Shipard\Base\Utility;


/**
 * class RepairFeesEngine
 */
class RepairFeesEngine extends Utility
{
  var $schoolYear;
  var $half = 0;
  var $addFee = 0;

  public function checkStudies()
  {
    if (!$this->half)
    {
      return;
    }
    $q = [];
    array_push($q, 'SELECT studium.*,');
    array_push($q, ' obory.skolne1p AS oborSkolne');
    array_push($q, ' FROM [e10pro_zus_studium] AS studium');
    array_push($q, ' LEFT JOIN e10pro_zus_obory AS obory ON studium.svpObor = obory.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND skolniRok = %s', $this->schoolYear);
    array_push($q, ' AND stav = %i', 1200);
    if ($this->half === 1)
      array_push($q, ' AND skolnePrvniPol != obory.skolne1p');
    elseif ($this->half === 2)
      array_push($q, ' AND skolneDruhePol != obory.skolne1p');
    //array_push($q, ' AND (obory.skolne1p - skolneDruhePol > 100)');
    array_push($q, ' ORDER BY cisloStudia');

    $cnt = 0;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($this->half === 1)
        $diffPrice = $r['oborSkolne'] - $r['skolnePrvniPol'];
      elseif ($this->half === 2)
        $diffPrice = $r['oborSkolne'] - $r['skolneDruhePol'];


      if ($this->addFee)
      {
        $update = [];
        if ($this->half === 1)
        {
          $update['skolnePrvniPol'] = $r['skolnePrvniPol'] + $this->addFee;
          $update['skolVyPrvniPol'] = $update ['skolnePrvniPol'] - $r ['skolSlPrvniPol'];
        }
        elseif ($this->half === 2)
        {
          $update['skolneDruhePol'] = $r['skolneDruhePol'] + $this->addFee;
          $update['skolVyDruhePol'] = $update ['skolneDruhePol'] - $r ['skolSlDruhePol'];
        }

        echo $r['nazev'].": {$r['oborSkolne']} x {$r['skolneDruhePol']}:    {$diffPrice} --> ".json_encode($update)."\n";

        $this->db()->query('UPDATE [e10pro_zus_studium] SET ', $update, ' WHERE ndx = %i', $r['ndx']);
      }
      else
      {
        echo $r['nazev'].": {$r['oborSkolne']} x {$r['skolneDruhePol']}:    {$diffPrice}"."\n";
      }

      $cnt++;
    }

    echo $cnt." bad prices\n";
  }

  public function run()
  {
    $this->checkStudies();
  }
}

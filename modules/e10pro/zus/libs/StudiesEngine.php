<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \Shipard\Utils\World;
use E10Pro\Zus\zusutils, \e10\utils, \e10\str, \e10\Utility;


/**
 * class StudiesEngine
 */
class StudiesEngine extends Utility
{
  var $schoolYearId = '';
  var $schoolYearCfg = NULL;
  var $debug = 0;
  var $doIt = 0;

  var $rocniky;

  var $cntEnding = 0;
  var $cntErrors = 0;

  /** @var \e10pro\zus\TableStudium */
  var $tableStudium;


  public function generateFromPastYear()
  {
    $q = [];
    array_push($q, 'SELECT *  FROM [e10pro_zus_studium] ');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [smazano] = %i', 0);
    array_push($q, ' AND [stavHlavni] < %i', 4);
    array_push($q, ' AND [skolniRok] = %s', '2022');

    $cnt = 1;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($this->debug)
        echo $cnt.'. '.$r['nazev'];

      $rocnikCfg = $this->rocniky [$r['rocnik']] ?? NULL;
      if (!$rocnikCfg)
      {
        if ($this->debug)
          echo "; CHYBA - neexistujici ročník\n";

        $this->cntErrors++;
        continue;
      }

      if (!Utils::dateIsBlank($r['datumUkonceniSkoly']))
      {
        if ($this->debug)
          echo "; END0\n";

        $this->cntEnding++;
				  continue;
      }

      if ($rocnikCfg['konecStudia'] == 1)
      {
        if ($this->debug)
          echo "; END1\n";

        $this->cntEnding++;
				continue;
      }

      if ($rocnikCfg['dalsiRocnik'])
        $dalsiRocnikCfg = $this->rocniky[$rocnikCfg['dalsiRocnik']] ?? NULL;
      else
        $dalsiRocnikCfg = Utils::searchArray($this->rocniky, 'pc', $rocnikCfg['pc'] + 1);

      if (!$dalsiRocnikCfg)
      {
        if ($this->debug)
          echo "; CHYBA - neexistující další ročník\n";

        $this->cntErrors++;
        continue;
      }

      if ($this->doIt)
      {
        $item = [
          'student' => $r ['student'], 'ucitel' => $r ['ucitel'],
          'typVysvedceni' => $dalsiRocnikCfg['typVysvedceni'],
          'skolniRok' => strval($r ['skolniRok'] + 1),
          'poradoveCislo' => $r ['cisloStudia'],
          'svp' => $r ['svp'], 'svpObor' => $r ['svpObor'], 'svpOddeleni' => $r ['svpOddeleni'],
          'rocnik' => $dalsiRocnikCfg['id'],
          'stupen' => $dalsiRocnikCfg['stupen'],
          'urovenStudia' => $r ['urovenStudia'],
          'cisloStudia' => $r ['cisloStudia'],

          'skolnePrvniPol' => $r ['skolneDruhePol'],
          'skolSlPrvniPol' => $r ['skolSlDruhePol'],
          'skolVyPrvniPol' => $r ['skolVyDruhePol'],
          'skolneDruhePol' => $r ['skolneDruhePol'],
          'skolSlDruhePol' => $r ['skolSlDruhePol'],
          'skolVyDruhePol' => $r ['skolVyDruhePol'],
          'bezDotace' => $r ['bezDotace'],
          'oznaceniStudia' => $r ['oznaceniStudia'],
          'pobocka' => $r ['pobocka'],
          'misto' => $r ['misto'],
          'stavHlavni' => 1, 'stav' => 1200,
          'datumNastupuDoSkoly' => $r ['datumNastupuDoSkoly'],
          'datumUkonceniSkoly' => $r ['datumUkonceniSkoly'],
        ];

        $studiumNdx =  $this->tableStudium->dbInsertRec ($item);

        $rows2 = $this->db()->query ('SELECT * FROM [e10pro_zus_studiumpre] WHERE [studium] = %i', $r ['ndx'],
                                      ' ORDER BY [ndx]')->fetchAll ();
        forEach ($rows2 as $row2)
        {
          $this->db()->query ('INSERT INTO e10pro_zus_studiumpre', [
                                'studium' => $studiumNdx,
                                'svpPredmet' => $row2 ['svpPredmet'],
                                'ucitel' => $row2 ['ucitel']
                              ]);
        }

        $this->tableStudium->docsLog ($studiumNdx);
      }

      if ($this->debug)
        echo "\n";
      $cnt++;
    }
  }

  public function generateFromEntries()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [e10pro_zus_prihlasky]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND talentovaZkouska = %i', 1);
    array_push($q, ' AND keStudiu = %i', 1);
    array_push($q, ' AND docState = %i', 4000);
    array_push($q, ' AND dstStudent != %i', 0);
    array_push($q, ' AND dstStudium = %i', 0);
    array_push($q, ' AND mistoStudia = %i', 1);

    $cnt = 1;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if ($this->debug)
        echo $cnt.'.'.$r['fullNameS'];

      $rocnikNdx = $r['rocnik'];
      $stupenNdx = 0;

      //$rocnikCfg = $this->rocniky [$r['rocnik']] ?? NULL;
      /*
      if ($rocnikCfg)
      {
        if ($this->debug)
          echo "; CHYBA - neexistujici ročník\n";

        $this->cntErrors++;
        continue;
      }
      */

      if ($this->doIt)
      {
        $item = [
          'student' => $r ['dstStudent'],
          'ucitel' => 0,
          //'typVysvedceni' => $rocnikCfg['typVysvedceni'],
          'skolniRok' => $this->schoolYearId,
          //'poradoveCislo' => $r ['cisloStudia'],
          'svp' => 2,
          'svpObor' => $r ['svpObor'],
          'svpOddeleni' => $r ['svpOddeleni'],
          'rocnik' => $rocnikNdx,
          'stupen' => $stupenNdx,
          'urovenStudia' => 1,
          'cisloStudia' => 0,
          'urovenStudia' => 1,

          'skolnePrvniPol' => 0,
          'skolSlPrvniPol' => 0,
          'skolVyPrvniPol' => 0,
          'skolneDruhePol' => 0,
          'skolSlDruhePol' => 0,
          'skolVyDruhePol' => 0,

          //'oznaceniStudia' => $r ['oznaceniStudia'],
          //'pobocka' => $r ['pobocka'],

          'misto' => $r ['misto'],
          'prihlaska' => $r['ndx'],
          'stavHlavni' => 0, 'stav' => 1000,

          'datumNastupuDoSkoly' => Utils::createDateTime($this->schoolYearCfg ['zacatek']),
        ];

        $studiumNdx =  $this->tableStudium->dbInsertRec ($item);

        $this->tableStudium->docsLog ($studiumNdx);

        $this->app()->db()->query('UPDATE [e10pro_zus_prihlasky] SET [dstStudium] = %i', $studiumNdx, ' WHERE [ndx] = %i', $r['ndx']);
      }

      if ($this->debug)
        echo "\n";
      $cnt++;
    }
  }

  public function run()
  {
		$this->rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');
    $this->tableStudium = $this->app()->table('e10pro.zus.studium');

    $this->schoolYearId = '2023';
    $this->schoolYearCfg = $this->app()->cfgItem ('e10pro.zus.roky.'.$this->schoolYearId);

    $this->generateFromPastYear();
    $this->generateFromEntries();

    if ($this->debug)
    {
      echo "Chyby: ".$this->cntErrors."\n";
      echo "Nepokračuje: ".$this->cntEnding."\n";
    }
  }
}


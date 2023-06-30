<?php
namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use e10pro\zus\zusutils;
use \Shipard\Utils\Utils, \Shipard\Utils\Str, \Shipard\Base\Utility;


/**
 * class KontrolaStudia
 */
class KontrolaStudia extends Utility
{
  var $studiumNdx = 0;
  var $studiumRecData = NULL;
  var $predmetyStudia = [];
  var $podobnePredmety = [];

  var $troubles = [];

  public function setStudium($studiumNdx)
  {
    $this->studiumNdx = $studiumNdx;
    $this->studiumRecData = $this->app()->loadItem($this->studiumNdx, 'e10pro.zus.studium');

    // -- podobne predmety
    $predmety = $this->app()->cfgItem ('e10pro.zus.predmety', []);
    foreach ($predmety as $predmetNdx => $pdef)
    {
      if (!isset($pdef['podobne']))
        continue;
      foreach ($pdef['podobne'] as $ppndx)
      {
        $this->podobnePredmety[$ppndx] = $predmetNdx;
      }
    }


    // -- predmety
		$q = [];
		array_push($q, 'SELECT studiumpre.*, ucitele.fullName as ucitel, predmety.nazev ');
		array_push($q, ' FROM [e10pro_zus_studiumpre] as studiumpre');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS ucitele ON studiumpre.ucitel = ucitele.ndx');
		array_push($q, ' LEFT JOIN [e10pro_zus_predmety] AS predmety ON studiumpre.svpPredmet = predmety.ndx');
    array_push($q, ' WHERE studiumpre.studium = %i', $this->studiumNdx);
    array_push($q, ' ORDER BY studiumpre.ndx');
		$rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $predmetNdx = $this->predmetNdx($r['svpPredmet']);
      $pDef = $this->app()->cfgItem ('e10pro.zus.predmety.'.$predmetNdx, FALSE);
      $this->predmetyStudia[$predmetNdx] = $pDef;
    }
  }

  protected function kontrolaETK()
  {
    $etkList = [];

    if ($this->studiumRecData['skolniRok'] == '')
      return;

    // -- individualni
    $q = [];
    array_push($q, 'SELECT vyuky.*');
    array_push($q, ' FROM [e10pro_zus_vyuky] AS vyuky');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [vyuky].[typ] = %i', 1);
    array_push($q, ' AND [vyuky].[stav] != %i', 9800);
    array_push($q, ' AND [vyuky].[studium] = %i', $this->studiumNdx);
    array_push($q, ' AND [vyuky].[skolniRok] = %i', $this->studiumRecData['skolniRok']);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $predmetNdx = $this->predmetNdx($r['svpPredmet']);
      $etkList [$predmetNdx][] = $r->toArray();
    }

    // -- kolektivni
    $q = [];
    array_push($q, 'SELECT vyuky.*');
    array_push($q, ' FROM [e10pro_zus_vyuky] AS vyuky');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [vyuky].[typ] = %i', 0);
    array_push($q, ' AND [vyuky].[stav] != %i', 9800);
    array_push($q, ' AND [vyuky].[skolniRok] = %i', $this->studiumRecData['skolniRok']);
    array_push ($q, 'AND EXISTS (',
    'SELECT vyuka FROM e10pro_zus_vyukystudenti AS vyukyStudenti',
    //' LEFT JOIN [e10pro_zus_studium] AS vyukyStudia ON vyukyStudenti.studium = vyukyStudia.ndx',
    ' WHERE vyukyStudenti.[studium] = %i', $this->studiumNdx,
    ' AND vyukyStudenti.vyuka = vyuky.ndx',
    ')');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $predmetNdx = $this->predmetNdx($r['svpPredmet']);
      $etkList [$predmetNdx][] = $r->toArray();

      if ($r['svpPredmet2'])
      {
        $predmetNdx = $this->predmetNdx($r['svpPredmet2']);
        $etkList [$predmetNdx][] = $r->toArray();
      }

      if ($r['svpPredmet3'])
      {
        $predmetNdx = $this->predmetNdx($r['svpPredmet3']);
        $etkList [$predmetNdx][] = $r->toArray();
      }
    }

    foreach ($this->predmetyStudia as $predmetNdx => $predmet)
    {
      if (!isset($etkList [$predmetNdx]) || !count($etkList [$predmetNdx]))
      {
				$this->troubles[] = [
					'msg' => 'Chybí ETK pro předmět '.$predmet['nazev'],
				];
      }
      elseif (count($etkList [$predmetNdx]) > 1)
      {
				$this->troubles[] = [
					'msg' => 'Více ETK pro předmět '.$predmet['nazev'],
					'date' => 'TEST2',
				];
      }
    }

    foreach ($etkList as $predmetNdx => $etks)
    {
      if (!isset($this->predmetyStudia[$predmetNdx]))
      {
        $pDef = $this->app()->cfgItem ('e10pro.zus.predmety.'.$predmetNdx, FALSE);

        $msg = [];
        $msg[] = ['text' => 'Existuje ETK pro předmět "'.$pDef['nazev'].'", který není ve studiu: ', 'class' => 'block'];

        foreach ($etks as $etk)
        {
          $msg[] = ['text' => $etk['nazev'], 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk' => $etk['ndx']];
        }

				$this->troubles[] = ['msg' => $msg];
      }
    }
  }

  protected function predmetNdx($predmetNdx)
  {
    if (isset($this->podobnePredmety[$predmetNdx]))
      return $this->podobnePredmety[$predmetNdx];
    return $predmetNdx;
  }

  public function run()
  {
    $this->kontrolaETK();
  }
}

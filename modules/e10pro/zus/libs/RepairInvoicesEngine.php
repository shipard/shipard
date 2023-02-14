<?php
namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/debs/debs.php';


use \Shipard\Base\Utility;


/**
 * class RepairInvoicesEngine
 */
class RepairInvoicesEngine extends Utility
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

    $dbCounterNdx = intval($this->app->cfgItem('options.e10-pro-zus.dbCounterInvoicesFeeSchool'));
    $y2 = substr(strval($this->schoolYear), 2);
    $schyId = $y2.strval((intval($y2)+1));

    $q = [];
    array_push($q, 'SELECT studium.*,');
    array_push($q, ' obory.skolne1p AS oborSkolne');
    array_push($q, ' FROM [e10pro_zus_studium] AS studium');
    array_push($q, ' LEFT JOIN e10pro_zus_obory AS obory ON studium.svpObor = obory.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND skolniRok = %s', $this->schoolYear);
    array_push($q, ' AND stav = %i', 1200);
    array_push($q, ' ORDER BY cisloStudia');

    $cnt = 0;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $symbol1 = strval($r['cisloStudia']);
      $symbol2 = $schyId.$this->half;

      $invoiceHead = $this->db()->query('SELECT * FROM [e10doc_core_heads] WHERE [dbCounter] = %i', $dbCounterNdx,
                                        ' AND symbol1 = %s', $symbol1,
                                        ' AND symbol2 = %s', $symbol2
                                        )->fetch();
      if (!$invoiceHead)
      {
        echo $r['nazev']." - INVOICE MISSING \n";
        continue;
      }

      $priceItem = 0;
      $diffPrice = 0;
      if ($this->half === 1)
      {
        if ($r['skolSlPrvniPol'] == $invoiceHead['toPay'])
          continue;
        $diffPrice = $r['skolSlPrvniPol'] - $invoiceHead['toPay'];
        $priceItem = $r['skolSlPrvniPol'];
      }
      if ($this->half === 2)
      {
        if ($r['skolVyDruhePol'] == $invoiceHead['toPay'])
          continue;
        $diffPrice = $r['skolVyDruhePol'] - $invoiceHead['toPay'];
        $priceItem = $r['skolVyDruhePol'];
      }

      echo $r['nazev'].": ";
      echo " [{$invoiceHead['docNumber']}] : ".$diffPrice;

      $invoiceRow = $this->db()->query('SELECT * FROM [e10doc_core_rows] WHERE [document] = %i', $invoiceHead['ndx'], ' LIMIT 1')->fetch();
      if (!$invoiceRow)
      {
        echo " --- !!! MISSING ROW !!! \n";
        continue;
      }

      $this->db()->query('UPDATE [e10doc_core_rows] SET [priceItem] = %i', $priceItem, ' WHERE [ndx] = %i', $invoiceRow['ndx']);

      $e = new \e10doc\core\libs\DocsChecks($this->app());
      $e->init();
      $e->recalcDocument2($invoiceHead['ndx']);

      echo "\n";
      $cnt++;
    }

    echo $cnt." bad invoices\n";
  }

  public function run()
  {
    $this->checkStudies();
  }
}

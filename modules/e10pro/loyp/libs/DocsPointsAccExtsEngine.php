<?php

namespace e10pro\loyp\libs;


/**
 * class DocsPointsAccExtsEngine
 */
class DocsPointsAccExtsEngine extends \Shipard\Base\Utility
{
  var ?\e10doc\debs\libs\AccountingDocEngine $accEngine = NULL;
  var $loypCfg = NULL;

  public function doDocument(\e10doc\debs\libs\AccountingDocEngine $accEngine)
  {
    $this->accEngine = $accEngine;

    $loypNdx = $this->accEngine->docHead['loyp'];
    if (!$loypNdx)
      return;

    $this->loypCfg = $this->app()->cfgItem('e10pro.loyp.loyps.'.$loypNdx, NULL);
    if (!$this->loypCfg)
      return;

    $debsAccIdDr = '';
    $debsAccIdCr = '';

    $dir = 0; // IN
    $rowTextBegin = 'Věrnostní body: ';

    if ($this->accEngine->docHead['docType'] === 'purchase')
    {
      $debsAccIdDr = $this->loypCfg ['debsAccIdInDr'];
      $debsAccIdCr = $this->loypCfg ['debsAccIdInCr'];
    }
    elseif ($this->accEngine->docHead['docType'] === 'invno')
    {
      $debsAccIdDr = $this->loypCfg ['debsAccIdOutDr'];
      $debsAccIdCr = $this->loypCfg ['debsAccIdOutCr'];
      $dir = 1; // OUT
    }

    if ($debsAccIdDr == '' || $debsAccIdCr == '')
      return;

    $pointAccPrice = $this->loypCfg ['pointAccPrice'];
    if ($dir === 1)
      $rowTextBegin = 'Uplatněné body: ';

    $q = [];
    array_push($q, 'SELECT SUM([cntPoints]) AS cntPoints');
    array_push($q, ' FROM [e10pro_loyp_pointsJournal]');
    array_push($q, ' WHERE [document] = %i', $this->accEngine->docHead['ndx']);
    $cntPointsRec = $this->app->db()->query($q)->fetch();
    if (!$cntPointsRec)
      return;
    $cntPoints = $cntPointsRec['cntPoints'];
    if ($dir === 1)
      $cntPoints = -$cntPoints; // OUT, points is negative

    $money = $cntPoints * $pointAccPrice;

    $rowDr = [
      'accountId' => $debsAccIdDr,
      'side' => 0,
      'money' => $money,
      'text' => $rowTextBegin . $cntPoints . ' x ' . $pointAccPrice . ' Kč',
    ];
    $this->addRow($rowDr);

    $rowCr = [
      'accountId' => $debsAccIdCr,
      'side' => 1,
      'money' => $money,
      'text' => $rowTextBegin . $cntPoints . ' x ' . $pointAccPrice . ' Kč',
    ];
    $this->addRow($rowCr);
  }

  protected function addRow($rowData)
  {
    $newRow = [];
    foreach ($rowData as $k => $v)
      $newRow[$k] = $v;

    $newRow['centre'] = 0;
    $newRow['project'] = 0;
    $newRow['workOrder'] = 0;
    $newRow['property'] = 0;
    $newRow['symbol1'] = '';
    $newRow['symbol2'] = '';
    $newRow['person'] = 0;
    $newRow['balance'] = 0;
    $newRow['cashBookId'] = 0;

    $newRow['accRing'] = 120;
    $this->accEngine->incRow($newRow);
  }
}

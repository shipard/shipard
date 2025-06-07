<?php

namespace e10pro\loyp\libs;


/**
 * class DocsPointsAccExtsEngineOut
 */
class DocsPointsAccExtsEngineOut extends \Shipard\Base\Utility
{
  var ?\e10doc\debs\libs\AccountingDocEngine $accEngine = NULL;
  var $loypCfg = NULL;

  public function doDocument(\e10doc\debs\libs\AccountingDocEngine $accEngine)
  {
    $this->accEngine = $accEngine;

    if ($this->accEngine->docHead['personType'] == 2) // companies
      return;

    $loypNdx = $this->accEngine->docHead['loyp'];
    if (!$loypNdx)
      return;

    $this->loypCfg = $this->app()->cfgItem('e10pro.loyp.loyps.'.$loypNdx, NULL);
    if (!$this->loypCfg)
      return;

    $debsAccIdBalanceDr = '';
    $debsAccIdBalanceCr = '';
    $debsAccIdCosts = '';
    $debsAccIdOutCosts = '';

    $dir = 0; // IN
    $rowTextBegin = 'Věrnostní body: ';

    if ($this->accEngine->docHead['docType'] === 'invno')
    {
      $debsAccIdCosts = $this->loypCfg ['debsAccIdInDr'] ?? '';
      $debsAccIdBalanceDr = $this->loypCfg ['debsAccIdInCr'] ?? '';
      $debsAccIdBalanceCr = $this->loypCfg ['debsAccIdOutBalanceDr'] ?? '';
      $debsAccIdOutCosts = $this->loypCfg ['debsAccIdOutCosts'] ?? '';
      $dir = 1; // OUT
    }

    if ($debsAccIdBalanceDr == '' || $debsAccIdBalanceCr == '' || $debsAccIdCosts == '')
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

    $moneyPoints = $cntPoints * $pointAccPrice;

    $moneyBalance = 0.0;

    foreach ($this->accEngine->docJournal as $jr)
    {
      if ($jr['accountId'] === $debsAccIdBalanceCr || substr($jr['accountId'], 0, 3) === '315')
        $moneyBalance += $jr['money'];
    }

    // -- balance DR/MD
    $rowDr = [
      'accountId' => $debsAccIdBalanceDr,
      'side' => 0,
      'money' => $moneyPoints,
      'text' => $rowTextBegin . $cntPoints . ' x ' . $pointAccPrice . ' Kč',
    ];
    $this->addRow($rowDr);

    // -- balance CR/DAL
    $rowCr = [
      'accountId' => $debsAccIdBalanceCr,
      'side' => 1,
      'symbol1' => $this->accEngine->docHead['symbol1'],
      'money' => $moneyBalance, //$moneyPoints,
      'text' => 'Úhrada pohledávky',
    ];
    $this->addRow($rowCr);

    $moneyCosts = round($moneyPoints - $moneyBalance, 2);
    if ($moneyCosts !== 0.0)
    {
      if ($moneyCosts > 0.0)
      {
        // -- costs DR/MD - negative
        $rowCostsCr = [
          'accountId' => $debsAccIdOutCosts,
          'side' => 0,
          'money' => $moneyCosts,
          'text' => 'Doúčtování nákladů na body',
        ];
      }
      else
      {
        // -- costs CR/DAL
        $rowCostsCr = [
          'accountId' => $debsAccIdOutCosts,
          'side' => 0,
          'money' => - $moneyCosts,
          'text' => 'Doúčtování nákladů na body',
        ];
      }
      $this->addRow($rowCostsCr);
    }
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
    if (!isset($newRow['symbol1']))
      $newRow['symbol1'] = '';
    $newRow['symbol2'] = '';
    $newRow['person'] = 0;
    $newRow['balance'] = 0;
    $newRow['cashBookId'] = 0;

    $newRow['accRing'] = 120;
    $this->accEngine->incRow($newRow);
  }
}

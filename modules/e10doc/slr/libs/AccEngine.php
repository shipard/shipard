<?php
namespace e10doc\slr\libs;
use \Shipard\Base\Utility;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Json;


/**
 * class AccEngine
 */
class AccEngine extends Utility
{
  var $empRecNdx = 0;
  var $empRecRecData = NULL;

  var $empRecData = NULL;
  var $importRecData = NULL;

  var $slrOrgRecData = NULL;

  var $rows = [];
  var $docRows = [];

  var $detailOverviewTable = [];
  var $detailOverviewHeader = [];

  var $slrItemTypes;

  public function setEmpRec($empRecNdx)
  {
    $this->empRecNdx = $empRecNdx;

    $this->empRecRecData = $this->app()->loadItem($this->empRecNdx, 'e10doc.slr.empsRecs');


    $this->empRecData = $this->app()->loadItem($this->empRecRecData['emp'], 'e10doc.slr.emps');
    $this->importRecData = $this->app()->loadItem($this->empRecRecData['import'], 'e10doc.slr.imports');

    $this->slrItemTypes = $this->app()->cfgItem('e10doc.slr.slrItemTypes');
  }

  protected function loadRows()
	{
    $q = [];
    array_push ($q, 'SELECT [recsRows].*, ');
		array_push ($q, ' slrItems.fullName AS srlItemName, slrItems.itemType AS slrItemType, ');
    array_push ($q, ' slrItems.accItemDr, slrItems.accItemCr, slrItems.moneyOrg AS slrOrg');
		array_push ($q, ' FROM [e10doc_slr_empsRecsRows] AS [recsRows]');
		array_push ($q, ' LEFT JOIN [e10doc_slr_slrItems] AS slrItems ON [recsRows].slrItem = slrItems.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND empsRec = %i', $this->empRecNdx);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = $r->toArray();
      $item['warnings'] = [];
      $item['info'] = [];

      $sit = $this->slrItemTypes[$r['slrItemType']];

      // -- check witems
      if (!$item['accItemDr'])
      {
        $item['warnings'][] = ['text' => 'Není nastavena položka MD', 'class' => 'block e10-warning1'];
      }
      if (!$item['accItemCr'])
      {
        $item['warnings'][] = ['text' => 'Není nastavena položka DAL', 'class' => 'block e10-warning1'];
      }

      // -- payment?
      if ($sit['payee'] === 1)
      { // emp
        $item['doPayment'] = 1;
        $item['bankAccount'] = $this->empRecData['slrBankAccount'];
        $item['symbol1'] = $this->empRecData['slrSymbol1'];
        $item['symbol2'] = $this->empRecData['slrSymbol2'];
        $item['symbol3'] = $this->empRecData['slrSymbol3'];

        if ($item['symbol1'] === '')
          $item['symbol1'] = $this->empRecData['personalId'];

        if ($this->importRecData)
          $item['symbol2'] .= sprintf("%02d%02d", ($this->importRecData['calendarYear'] - 2000), $this->importRecData['calendarMonth']);
      }
      elseif ($sit['payee'] === 2)
      { // org
        $slrOrgRecData = $this->app()->loadItem($r['slrOrg'], 'e10doc.slr.orgs');

        if ($r['slrOrg'])
        {
          $paymentOrgRecData = $slrOrgRecData;

          $item['doPayment'] = 1;
          $item['bankAccount'] = $paymentOrgRecData['bankAccount'];
          $item['symbol1'] = $paymentOrgRecData['symbol1'];
          $item['symbol2'] = $paymentOrgRecData['symbol2'];
          $item['symbol3'] = $paymentOrgRecData['symbol3'];

          $item['symbol2'] .= sprintf("%02d%02d", ($this->importRecData['calendarYear'] - 2000), $this->importRecData['calendarMonth']);
        }
      }

      if ($item['doPayment'])
      {
        if ($item['bankAccount'] !== '')
          $item['info'][] = ['text' => $item['bankAccount'], 'icon' => 'paymentMethodTransferOrder', 'class' => ''];
        else
          $item['warnings'][] = ['text' => 'Chybí bankovní účet pro úhradu', 'class' => 'block e10-warning1'];

        $item['info'][] = ['text' => $item['symbol1'], 'prefix' => 'VS', 'class' => 'label label-default'];
        $item['info'][] = ['text' => $item['symbol2'], 'prefix' => 'SS', 'class' => 'label label-default'];

        if ($item['symbol3'] !== '')
          $item['info'][] = ['text' => $item['symbol3'], 'prefix' => 'KS', 'class' => 'label label-default'];
      }

      $this->rows[] = $item;
    }
	}

  protected function createDocRows()
  {
    foreach ($this->rows as $r)
    {
      $docRow = [

      ];
    }
  }

  protected function createOverview()
  {
    $lastSit = -1;
    $sitSum = 0;
    $empSum = 0;

    foreach ($this->slrItemTypes as $sitNdx => $sit)
    {
      $cnt = 0;
      $sitSum = 0;
      foreach ($this->rows as $r)
      {
        if ($r['slrItemType'] !== $sitNdx)
          continue;

        $item = [
          'slrItem' => [['text' => $r['srlItemName'], 'class' => 'e10-bold block']],
          'amount' => $r['amount'],
        ];

        if (count($r['warnings']))
        {
          $item['slrItem'] = array_merge($item['slrItem'], $r['warnings']);
        }

        if (count($r['info']))
        {
          $item['slrItem'] = array_merge($item['slrItem'], $r['info']);
        }


        $this->detailOverviewTable[] = $item;

        $sitSum += $r['amount'];
        if ($sitNdx >= 40)
          $empSum += $r['amount'];

        $cnt++;
      }

      if ($cnt)
      {
        $this->detailOverviewTable[] = [
          'slrItem' => $sit['fn'],
          'amount' => $sitSum,
          '_options' => ['afterSeparator' => 'separator', 'class' => 'subtotal'],
        ];
      }
    }

    $this->detailOverviewTable[] = [
      'slrItem' => 'Celkové náklady na zaměstnance',
      'amount' => $empSum,
      '_options' => ['__afterSeparator' => 'separator', 'class' => 'sumtotal'],
    ];


    $this->detailOverviewHeader = ['#' => '#', 'slrItem' => 'Položka', 'amount' => ' Částka'];
  }

  public function loadData()
  {
    $this->loadRows();
    $this->createOverview();
  }
}

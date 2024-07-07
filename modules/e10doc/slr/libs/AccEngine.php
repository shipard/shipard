<?php
namespace e10doc\slr\libs;
use \Shipard\Base\Utility;
use \e10doc\core\libs\CreateDocumentUtility;


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

  var $nextMonthFirstDay = NULL;
  var $accountingFirstDay = NULL;
  var $rows = [];
  var $docRows = [];

  var $detailOverviewTable = [];
  var $detailOverviewHeader = [];

  var $slrItemTypes;
  var $accounts = [];
  var $witems = [];

  var $empCosts = 0.0;

  var $rowOrder;

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
    array_push ($q, ' slrItems.accItemDr, slrItems.accItemCr, slrItems.accItemBal, slrItems.moneyOrg AS slrOrg,');
    array_push ($q, ' slrItems.fullName AS slrItemName, slrItems.dueDay AS slrItemDueDay,');
    array_push ($q, ' [centres].[id] AS [centreId]');
		array_push ($q, ' FROM [e10doc_slr_empsRecsRows] AS [recsRows]');
		array_push ($q, ' LEFT JOIN [e10doc_slr_slrItems] AS slrItems ON [recsRows].slrItem = slrItems.ndx');
    array_push ($q, ' LEFT JOIN [e10doc_base_centres] AS [centres] ON [recsRows].[centre] = [centres].ndx');
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
        $item['person'] = $this->empRecData['person'];

        $dueDays = ($r['slrItemDueDay']) ? $r['slrItemDueDay'] - 1 : 14;
        $dateDue = new \DateTime($this->nextMonthFirstDay->format('Y-m-d'));
        $dateDue->add(new \DateInterval('P'.$dueDays.'D'));
        $item['dateDue'] = $dateDue;

        if ($item['symbol1'] === '')
          $item['symbol1'] = $this->empRecData['personalId'];

        if ($this->importRecData)
          $item['symbol2'] .= sprintf("%04d%02d", $this->importRecData['calendarYear'], $this->importRecData['calendarMonth']);
      }
      elseif ($sit['payee'] === 2)
      { // org
        $slrOrgRecData = $this->searchPaymentOrg($r['slrItem'], $this->empRecData['ndx']);

        if (!$slrOrgRecData)
          $slrOrgRecData = $this->app()->loadItem($r['slrOrg'], 'e10doc.slr.orgs');

        if ($r['slrOrg'])
        {
          $paymentOrgRecData = $slrOrgRecData;

          $item['doPayment'] = 1;
          $item['bankAccount'] = $paymentOrgRecData['bankAccount'];
          $item['symbol1'] = $paymentOrgRecData['symbol1'];
          $item['symbol2'] = $paymentOrgRecData['symbol2'];
          $item['symbol3'] = $paymentOrgRecData['symbol3'];
          $item['person'] = $paymentOrgRecData['person'];

          $dueDays = ($r['slrItemDueDay']) ? $r['slrItemDueDay'] - 1 : 14;
          $dateDue = new \DateTime($this->nextMonthFirstDay->format('Y-m-d'));
          $dateDue->add(new \DateInterval('P'.$dueDays.'D'));
          $item['dateDue'] = $dateDue;

          $item['symbol2'] .= sprintf("%04d%02d", $this->importRecData['calendarYear'], $this->importRecData['calendarMonth']);
        }
      }
      elseif ($sit['payee'] === 3)
      { // deduction
        $deduction = $this->searchDeduction($this->empRecData['ndx'], $r->toArray());

        $item['doPayment'] = 1;
        if ($deduction)
        {
          $item['bankAccount'] = $deduction['bankAccount'];
          $item['symbol1'] = $deduction['symbol1'];
          $item['symbol2'] = $deduction['symbol2'];
          $item['symbol3'] = $deduction['symbol3'];
          $item['person'] = $deduction['payTo'];
        }
        else
        {
          $item['bankAccount'] = '';
          $item['symbol1'] = '';
          $item['symbol2'] = '';
          $item['symbol3'] = '';
          $item['person'] = 0;
        }

        $dueDays = ($r['slrItemDueDay']) ? $r['slrItemDueDay'] - 1 : 14;
        $dateDue = new \DateTime($this->nextMonthFirstDay->format('Y-m-d'));
        $dateDue->add(new \DateInterval('P'.$dueDays.'D'));
        $item['dateDue'] = $dateDue;
      }

      if ($item['doPayment'] ?? 0)
      {
        if (!isset($item['person']) || !$item['person'])
          $item['warnings'][] = ['text' => 'Chybí Osoba pro úhradu', 'class' => 'block e10-warning1'];

        if ($item['bankAccount'] !== '')
          $item['info'][] = ['text' => $item['bankAccount'], 'icon' => 'paymentMethodTransferOrder', 'class' => 'break'];
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
    $this->empCosts  = 0.0;

    foreach ($this->slrItemTypes as $sitNdx => $sit)
    {
      if (isset($sit['ignore']))
        continue;

      $cnt = 0;
      $sitSum = 0;
      foreach ($this->rows as $r)
      {
        if ($r['slrItemType'] !== $sitNdx)
          continue;

        // -- debit / MD
        $docRowDr = [
          'item' => $r['accItemDr'],
          'debit' => $r['amount'],
          'person' => $this->empRecData['person'],
          'text' => $r['slrItemName'],
          'centre' => $r['centre'] ?? 0,
        ];

        if (!$docRowDr['centre'] && $this->empRecData['centre'])
          $docRowDr['centre'] = $this->empRecData['centre'];

        // -- credit / DAL
        $docRowCr = [
          'item' => $r['accItemCr'],
          'credit' => $r['amount'],
          'person' => $r['person'] ?? 0,
          'text' => $r['slrItemName'],
        ];

        if ($r['accItemBal'])
        {
          $docRowCr['item'] = $r['accItemBal'];
        }
        else
        {
          if ($r['doPayment'] ?? 0)
          {
            $docRowCr['bankAccount'] = $r['bankAccount'];
            $docRowCr['symbol1'] = $r['symbol1'];
            $docRowCr['symbol2'] = $r['symbol2'];
            $docRowCr['symbol3'] = $r['symbol3'];

            if (isset($r['dateDue']))
              $docRowCr['dateDue'] = $r['dateDue'];
          }
          else
          {
            $docRowCr['person'] = $this->empRecData['person'];
          }
        }

        $drAccAccount = $this->accAccount($docRowDr['item']);
        if ($drAccAccount)
        {
          if ($drAccAccount['accountKind'] === 2)
            $this->empCosts += $docRowDr['debit'];
        }

        $this->docRows[] = $docRowDr;
        $this->docRows[] = $docRowCr;
      }
    }
  }

  public function addDocBalanceRows(&$dest)
  {
    $this->loadData();

    foreach ($this->slrItemTypes as $sitNdx => $sit)
    {
      if (isset($sit['ignore']))
        continue;

      foreach ($this->rows as $r)
      {
        if ($r['slrItemType'] !== $sitNdx)
          continue;
        if (!$r['accItemBal'])
          continue;

        // -- debit / MD
        $docRowDr = [
          'item' => $r['accItemBal'],
          'debit' => $r['amount'],
          'person' => 0,
          'bankAccount' => '', 'symbol1' => '', 'symbol2' => '', 'symbol3' => '',
        ];

        // -- credit / DAL
        $docRowCr = [
          'item' => $r['accItemCr'],
          'credit' => $r['amount'],
          'person' => $r['person'] ?? 0,
          'bankAccount' => '', 'symbol1' => '', 'symbol2' => '', 'symbol3' => '',
        ];
        if ($r['doPayment'])
        {
          $docRowCr['bankAccount'] = $r['bankAccount'];
          $docRowCr['symbol1'] = $r['symbol1'];
          $docRowCr['symbol2'] = $r['symbol2'];
          $docRowCr['symbol3'] = $r['symbol3'];

          if (isset($r['dateDue']))
            $docRowCr['dateDue'] = $r['dateDue'];
        }

        $dest[] = $docRowDr;
        $dest[] = $docRowCr;
      }
    }
  }

  protected function createOverview()
  {
    $sitSum = 0;

    foreach ($this->slrItemTypes as $sitNdx => $sit)
    {
      $cnt = 0;
      $sitSum = 0;
      foreach ($this->rows as $r)
      {
        if ($r['slrItemType'] !== $sitNdx)
          continue;

        $item = [
          'slrItem' => [['text' => $r['srlItemName'], 'class' => 'e10-bold']],
          'amount' => $r['amount'],
        ];

        if ($r['centreId'])
          $item['slrItem'][] = ['text' => $r['centreId'], 'class' => 'label label-default pull-right', 'icon' => 'tables/e10doc.base.centres'];

        if (count($r['warnings']))
        {
          $item['slrItem'] = array_merge($item['slrItem'], $r['warnings']);
        }

        if (count($r['info']))
        {
          $item['slrItem'] = array_merge($item['slrItem'], $r['info']);
        }

        if (isset($sit['ignore']))
          $item['_options']['class'] = 'e10-warning3';

        $this->detailOverviewTable[] = $item;

        $sitSum += $r['amount'];

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
      'amount' => $this->empCosts,
      '_options' => ['__afterSeparator' => 'separator', 'class' => 'sumtotal'],
    ];

    $this->detailOverviewHeader = ['#' => '#', 'slrItem' => 'Položka', 'amount' => ' Částka'];
  }

  protected function searchPaymentOrg($slrItemNdx, $empNdx)
  {
    $q = [];
    array_push($q, 'SELECT * FROM [e10doc_slr_empsOrgs]');
    array_push($q, ' WHERE [emp] = %i', $empNdx);
    array_push($q, ' AND [slrItem] = %i', $slrItemNdx);
		array_push($q, ' AND ([validFrom] IS NULL OR [validFrom] <= %d)', $this->accountingFirstDay);
		array_push($q, ' AND ([validTo] IS NULL OR [validTo] >= %d)', $this->accountingFirstDay);

    $empOrg = $this->db()->query($q)->fetch();
    if ($empOrg)
    {
      $orgRecData = $this->app()->loadItem($empOrg['org'], 'e10doc.slr.orgs');
      return $orgRecData;
    }

    return NULL;
  }

  protected function searchDeduction($empNdx, $empRecRow)
  {
    $q = [];
    array_push($q, 'SELECT * FROM [e10doc_slr_deductions]');
    array_push($q, ' WHERE [emp] = %i', $empNdx);
    array_push($q, ' AND [slrItem] = %i', $empRecRow['slrItem']);
    array_push($q, ' AND [bankAccount] = %s', $empRecRow['bankAccount']);
    array_push($q, ' AND [symbol1] = %s', $empRecRow['symbol1']);
    array_push($q, ' AND [symbol2] = %s', $empRecRow['symbol2']);
    array_push($q, ' AND [symbol3] = %s', $empRecRow['symbol3']);
    array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' AND ([validFrom] IS NULL OR [validFrom] <= %d)', $this->accountingFirstDay);
		array_push($q, ' AND ([validTo] IS NULL OR [validTo] >= %d)', $this->accountingFirstDay);

    $deduction = $this->db()->query($q)->fetch();
    if ($deduction)
    {
      return $deduction->toArray();
    }

    return NULL;
  }

  protected function accAccount($itemNdx)
  {
    $accountId = '';

    if (isset($this->witems[$itemNdx]))
      $accountId = $this->witems[$itemNdx]['debsAccountId'];

    if ($accountId === '')
    {
      $itemRecData = $this->app()->loadItem($itemNdx, 'e10.witems.items');
      $this->witems[$itemNdx] = $itemRecData;
      $accountId = $itemRecData['debsAccountId'];
    }

    if (isset($this->accounts[$accountId]))
      return $this->accounts[$accountId];

    $rows = $this->db()->query('SELECT * FROM e10doc_debs_accounts WHERE docStateMain < 3 AND [id] = %s', $accountId);
    foreach ($rows as $r)
    {
      $this->accounts[$accountId] = $r->toArray();
      return $this->accounts[$accountId];
    }

    $this->accounts[$accountId] = NULL;
    return NULL;
  }

  public function loadData()
  {
    $this->accountingFirstDay = new \DateTime (sprintf("%04d-%02d-01", $this->importRecData['calendarYear'], $this->importRecData['calendarMonth']));
    $this->nextMonthFirstDay = new \DateTime($this->accountingFirstDay->format('Y-m-t'));
    $this->nextMonthFirstDay->add(new \DateInterval('P1D'));

    $this->loadRows();
    $this->createDocRows();

    $this->createOverview();
  }

  public function generateAccDoc()
  {
    $this->loadData();

		$dbCounter = $this->app()->cfgItem ('options.e10doc-finance.slrAccDocDbCounter', 0);
    if (!$dbCounter)
      return;

    $this->rowOrder = 100;

    $accountingFirstDay = new \DateTime (sprintf("%04d-%02d-01", $this->importRecData['calendarYear'], $this->importRecData['calendarMonth']));
    $accDate = new \DateTime($accountingFirstDay->format('Y-m-t'));

		$newDoc = new CreateDocumentUtility ($this->app);
		$newDoc->createDocumentHead('cmnbkp');

		$newDoc->docHead['person'] = $this->empRecData['person'];
    $newDoc->docHead['dateAccounting'] = $accDate;
    $newDoc->docHead['dateTax'] = $accDate;
		$newDoc->docHead['author'] = $this->app()->userNdx();
    $newDoc->docHead['dbCounter'] = $dbCounter;
		$newDoc->docHead['title'] = 'Mzdy '.sprintf("%04d/%02d", $this->importRecData['calendarYear'], $this->importRecData['calendarMonth']).': '.$this->empRecData['fullName'];

    foreach ($this->docRows as $docRow)
    {
      $this->addDocRowDebit($newDoc, $docRow);
    }

    // -- inbox
		$fromTableId = 'e10doc.slr.imports';
		$docLinkId = 'e10doc-slr-imports-inbox';

    $q = [];
		array_push($q, 'SELECT * FROM [e10_base_doclinks]');
    array_push($q, ' WHERE linkId = %s', $docLinkId);
		array_push($q, ' AND srcTableId = %s', $fromTableId);
		array_push($q, ' AND srcRecId = %i', $this->empRecRecData['import']);
		$rows = $this->db()->query ($q);
    foreach ($rows as $r)
      $newDoc->addInbox($r['dstRecId']);

    // -- save
		$docNdx = $newDoc->saveDocument(CreateDocumentUtility::sdsConfirmed, intval($this->empRecRecData['docAcc']));

    $this->db()->query('UPDATE [e10doc_slr_empsRecs] SET [docAcc] = %i', $docNdx, ' WHERE [ndx] = %i', $this->empRecNdx);
  }

  public function addDocRowDebit(CreateDocumentUtility $newDoc, $docRow)
  {
    $newRow = $newDoc->createDocumentRow();
    foreach ($docRow as $key => $value)
      $newRow[$key] = $value;

    $newRow['operation'] = '1099998';
    $newRow['rowOrder'] = $this->rowOrder;

    $this->rowOrder += 100;

    $newDoc->addDocumentRow ($newRow);
  }
}

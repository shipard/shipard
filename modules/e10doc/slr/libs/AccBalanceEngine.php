<?php
namespace e10doc\slr\libs;
use \Shipard\Base\Utility;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Json;
use \e10doc\core\libs\CreateDocumentUtility;
use \Shipard\Utils\Utils;

/**
 * class AccBalanceEngine
 */
class AccBalanceEngine extends Utility
{
  var $importNdx = 0;
  var $importRecData = NULL;

  var $docRows = [];
  var $docRowsPacked = [];
  var $rowOrder;

  public function setImport($importNdx)
  {
    $this->importNdx = $importNdx;
    $this->importRecData = $this->app()->loadItem($this->importNdx, 'e10doc.slr.imports');
  }

  protected function loadData()
  {
    $this->docRows = [];
		$q = [];
    array_push ($q, 'SELECT [empsRecs].*');
		array_push ($q, ' FROM [e10doc_slr_empsRecs] AS [empsRecs]');
		array_push ($q, ' WHERE [empsRecs].[import] = %i', $this->importNdx);
		array_push ($q, ' AND [empsRecs].docState != %i', 9800);
		array_push ($q, ' ORDER BY [empsRecs].ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $ae = new \e10doc\slr\libs\AccEngine($this->app());
      $ae->setEmpRec($r['ndx']);
      $ae->addDocBalanceRows($this->docRows);
    }

    // -- pack rows
    foreach ($this->docRows as $docRow)
    {
      $packId = $docRow['item'].'_'.$docRow['person'].'_'.$docRow['bankAccount'].'_'.$docRow['symbol1'].'_'.$docRow['symbol2'].'_'.$docRow['symbol3'];
      if (!isset($this->docRowsPacked[$packId]))
      {
        $this->docRowsPacked[$packId] = $docRow;
        $itemRecData = $this->app()->loadItem($docRow['item'], 'e10.witems.items');
        if ($itemRecData)
          $this->docRowsPacked[$packId]['text'] = $itemRecData['fullName'];
      }
      else
      {
        if (isset($docRow['debit']))
          $this->docRowsPacked[$packId]['debit'] += $docRow['debit'];
        else
          $this->docRowsPacked[$packId]['credit'] += $docRow['credit'];
      }
    }

    // -- round
    $rowOrder = 100;
    $roundingRows = [];
    foreach ($this->docRowsPacked as $packId => $row)
    {
      $this->docRowsPacked[$packId]['rowOrder'] = $rowOrder;
      $rowOrder += 100;

      if (!isset($this->docRowsPacked[$packId]['credit']))
        continue;
      if (1)
      {
        $roundMethod = $this->app()->cfgItem ('e10.docs.roundMethods.' . '2', 0); // Koruny nahoru
        $roundedAmount = Utils::e10round($this->docRowsPacked[$packId]['credit'], $roundMethod);
        if (strval($roundedAmount) !== strval($this->docRowsPacked[$packId]['credit']))
        {
          $rounding = Utils::round($roundedAmount - $this->docRowsPacked[$packId]['credit'], 2, 0);
          $this->docRowsPacked[$packId]['credit'] = $roundedAmount;
          if ($rounding > 0)
          {
            $accItemRound = $this->app()->cfgItem ('options.e10doc-finance.accItemRoundCost', 0);
            $rndRow = [
              'item' => $accItemRound,
              'debit' => $rounding,
              'person' => 0,
              'text' => 'Zaokrouhlení: '.$row['text'],
              'centre' => $row['centre'] ?? 0,
              'rowOrder' => $this->docRowsPacked[$packId]['rowOrder'] - 10,
            ];
            $roundingRows[] = $rndRow;
          }
          else
          {
            $accItemRound = $this->app()->cfgItem ('options.e10doc-finance.accItemRoundProfit', 0);
            $rndRow = [
              'item' => $accItemRound,
              'credit' => $rounding,
              'person' => 0,
              'text' => 'Zaokrouhlení: '.$row['text'],
              'centre' => $row['centre'] ?? 0,
              'rowOrder' => $this->docRowsPacked[$packId]['rowOrder'] - 10,
            ];
            $roundingRows[] = $rndRow;
          }
        }
      }
    }
    if (count($roundingRows))
    {
      $this->docRowsPacked = array_merge($this->docRowsPacked, $roundingRows);
    }
  }

  public function generateAccBalanceDoc()
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

		$newDoc->docHead['person'] = intval($this->app()->cfgItem ('options.core.ownerPerson', 0));
    $newDoc->docHead['dateAccounting'] = $accDate;
    $newDoc->docHead['dateTax'] = $accDate;
		$newDoc->docHead['author'] = $this->app()->userNdx();
    $newDoc->docHead['dbCounter'] = $dbCounter;
		$newDoc->docHead['title'] = 'Závazky z mezd '.sprintf("%04d/%02d", $this->importRecData['calendarYear'], $this->importRecData['calendarMonth']);

    foreach ($this->docRowsPacked as $docRow)
    {
      $this->addDocRow($newDoc, $docRow);
    }

    // -- inbox
		$fromTableId = 'e10doc.slr.imports';
		$docLinkId = 'e10doc-slr-imports-inbox';

    $q = [];
		array_push($q, 'SELECT * FROM [e10_base_doclinks]');
    array_push($q, ' WHERE linkId = %s', $docLinkId);
		array_push($q, ' AND srcTableId = %s', $fromTableId);
		array_push($q, ' AND srcRecId = %i', $this->importNdx);
		$rows = $this->db()->query ($q);
    foreach ($rows as $r)
      $newDoc->addInbox($r['dstRecId']);

    // -- save
		$docNdx = $newDoc->saveDocument(CreateDocumentUtility::sdsConfirmed, intval($this->importRecData['docAccBal']));

    $this->db()->query('UPDATE [e10doc_slr_imports] SET [docAccBal] = %i', $docNdx, ' WHERE [ndx] = %i', $this->importNdx);
  }

  public function addDocRow(CreateDocumentUtility $newDoc, $docRow)
  {
    $newRow = $newDoc->createDocumentRow();
    foreach ($docRow as $key => $value)
      $newRow[$key] = $value;

    $newRow['operation'] = '1099998';
    //$newRow['rowOrder'] = $this->rowOrder;

    $this->rowOrder += 100;

    $newDoc->addDocumentRow ($newRow);
  }
}

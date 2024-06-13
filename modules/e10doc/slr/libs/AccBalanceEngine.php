<?php
namespace e10doc\slr\libs;
use \Shipard\Base\Utility;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Json;
use \e10doc\core\libs\CreateDocumentUtility;


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

		$docNdx = $newDoc->saveDocument(CreateDocumentUtility::sdsConfirmed, intval($this->importRecData['docAccBal']));

    $this->db()->query('UPDATE [e10doc_slr_imports] SET [docAccBal] = %i', $docNdx, ' WHERE [ndx] = %i', $this->importNdx);
  }

  public function addDocRow(CreateDocumentUtility $newDoc, $docRow)
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

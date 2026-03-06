<?php
namespace e10pro\loyp\libs;

use \Shipard\Base\Utility;
use \e10doc\core\libs\CreateDocumentUtility;


/**
 * class LoypPurchaseSaleDocGenerator
 */
class LoypPurchaseSaleDocGenerator extends Utility
{
  var $purchaseDocNdx = 0;
  var $purchaseDocRecData = NULL;
  var $docRows = [];

  var $rowOrder = 100;

  protected function loadData()
  {
    $this->docRows = [];
		$q = [];
    array_push ($q, 'SELECT [rows].*,');
    array_push ($q, ' [items].[fullName] AS [itemFullName], [items].[priceSellBase] AS itemPriceSellBase');
		array_push ($q, ' FROM [e10doc_core_rows] AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [rows].[item] = [items].ndx');
		array_push ($q, ' WHERE [rows].[document] = %i', $this->purchaseDocNdx);
		array_push ($q, ' AND [rows].itemIsLoyp = %i', 1);
		array_push ($q, ' ORDER BY [rows].ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $this->docRows[] = $r->toArray();
    }
  }

  public function doDocument(&$docRecData, $doSave = 0)
  {
    if (!$docRecData['loyp'])
      return;

    $loypCfg = $this->app()->cfgItem('e10pro.loyp.loyps.'.$docRecData['loyp'], NULL);
    if (!$loypCfg)
      return;

    $this->purchaseDocNdx = $docRecData['ndx'];
    $this->purchaseDocRecData = $docRecData;

    $this->loadData();
    if (!count($this->docRows))
      return;

    $dbCounter = intval($loypCfg['dbCounterInvoiceOut'] ?? 0);
    if (!$dbCounter)
      return;
    $warehouse = intval($loypCfg['warehouse'] ?? 0);

    $accDate = new \DateTime($this->purchaseDocRecData['dateAccounting']);
		$newDoc = new CreateDocumentUtility ($this->app);
		$newDoc->createDocumentHead('invno');
    $newDoc->docHead['loyp'] = $docRecData['loyp'];
    $newDoc->docHead['loypOtherDoc'] = $this->purchaseDocRecData['ndx'];
		$newDoc->docHead['person'] = $this->purchaseDocRecData['person'];
    $newDoc->docHead['dateAccounting'] = $accDate;
    $newDoc->docHead['dateTax'] = $accDate;
		$newDoc->docHead['author'] = $this->app()->userNdx();
    $newDoc->docHead['warehouse'] = $warehouse;
    $newDoc->docHead['dbCounter'] = $dbCounter;
    $newDoc->docHead['docKind'] = $loypCfg['docKindInvoiceOut'] ?? 0;
		$newDoc->docHead['title'] = 'Dárek k výkupu '.$this->purchaseDocRecData['docNumber'];

    foreach ($this->docRows as $docRow)
    {
      $this->addDocRow($newDoc, $docRow);
    }

    // -- save
		$docNdx = $newDoc->saveDocument(CreateDocumentUtility::sdsDone, $this->purchaseDocRecData['loypOtherDoc']);
    if ($this->purchaseDocRecData['loypOtherDoc'] == 0)
    {
      $this->db()->query ('UPDATE [e10doc_core_heads] SET loypOtherDoc = %i', $docNdx, ' WHERE ndx = %i', $this->purchaseDocRecData['ndx']);
    }
  }

  public function addDocRow(CreateDocumentUtility $newDoc, $docRow)
  {
    $newRow = $newDoc->createDocumentRow();

    $newRow['operation'] = '1010002'; // stock sale
    $newRow['item'] = $docRow['item'];
    $newRow['quantity'] = $docRow['quantity'];
    $newRow['unit'] = $docRow['unit'];
    $newRow['text'] = $docRow['itemFullName'];
    $newRow['priceItem'] = $docRow['itemPriceSellBase'];
    $newRow['rowOrder'] = $this->rowOrder;

    $existedLPPrice = $this->db()->query('SELECT * FROM [e10pro_loyp_priceListPoints] WHERE [item] = %i', $docRow['item'],
                                         ' AND ([validFrom] IS NULL OR [validFrom] <= %d)', $newDoc->docHead['dateAccounting'],
                                         ' AND ([validTo] IS NULL OR [validTo] >= %d)', $newDoc->docHead['dateAccounting'],
                                         ' AND [docState] IN %in', [4000, 8000],

                      )->fetch();

    if ($existedLPPrice)
    {
      $newRow['lpPriceItem'] = $existedLPPrice['pricePoints'];
      $newRow['lpPriceAll'] = intval($existedLPPrice['pricePoints'] * $newRow['quantity']);
    }

    $this->rowOrder += 100;

    $newDoc->addDocumentRow ($newRow);
  }
}

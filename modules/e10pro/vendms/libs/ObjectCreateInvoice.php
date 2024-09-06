<?php

namespace e10pro\vendms\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;
use \e10doc\core\libs\CreateDocumentUtility;


/**
 * class ObjectCreateInvoice
 */
class ObjectCreateInvoice extends Utility
{
  var $requestParams = NULL;
  var $result = ['success' => 0];

  public function saveDoc()
  {
		$dbCounter = 1;
    if (!$dbCounter)
    {
      return;
    }

    $itemRecData = $this->app()->loadItem($this->requestParams['itemNdx'] ?? 0, 'e10.witems.items');

    $rowOrder = 100;

    $accDate = Utils::today();

		$newDoc = new CreateDocumentUtility ($this->app);
		$newDoc->createDocumentHead('invno');

		$newDoc->docHead['person'] = $this->requestParams['personNdx'] ?? 0;
    $newDoc->docHead['dateAccounting'] = $accDate;
    $newDoc->docHead['dateTax'] = $accDate;
		$newDoc->docHead['author'] = $this->app()->userNdx();
    $newDoc->docHead['dbCounter'] = $dbCounter;
		$newDoc->docHead['title'] = 'Nákup v automatu';
    $newDoc->docHead['warehouse'] = 1;

    $docRow = [
      'operation' => '1010002',
      'item' => $this->requestParams['itemNdx'],
      'text' => $itemRecData['fullName'] ?? '!!!',
      'priceItem' => $itemRecData['priceSellTotal'] ?? 0,
      'rowOrder' => 100,
    ];
    $newDoc->addDocumentRow ($docRow);

    // -- save
		$docNdx = $newDoc->saveDocument(CreateDocumentUtility::sdsDone);

    $journalItem = [
      'created' => new \DateTime(),
      'vm' => 1,
      'item' => $this->requestParams['itemNdx'],
      'box' => $this->requestParams['boxNdx'],
      'moveType' => 0, 'quantity' => -1,
      'doc' => $docNdx,
    ];

    $this->db()->query('INSERT INTO [e10pro_vendms_vendmsJournal] ', $journalItem);

    $this->result['docNdx'] = $docNdx;
    $this->result['success'] = 1;
  }

  public function run()
  {
    $this->saveDoc();
  }
}

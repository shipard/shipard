<?php
namespace e10doc\cmnbkp\libs\imports\cardTrans;

use \Shipard\Utils\Xml;
use \Shipard\Utils\Json;
use \e10doc\core\libs\CreateDocumentUtility;


/**
 * class ImportCZCSOBCardTransactions
 */
class ImportCZCSOBCardTransactions extends \e10doc\cmnbkp\libs\imports\cardTrans\ImportCardTrans
{
  var $trans = [];

  public function doImport()
  {
		foreach ($this->files as $oneFile)
		{
      $path_parts = pathinfo ($oneFile);
      if ($path_parts ['extension'] !== 'xml')
        continue;

      $dataStr = file_get_contents($oneFile);
      $data = Xml::toArray($dataStr);

      file_put_contents('tmp/____00-tst.json', Json::lint($data));

      $this->importFile($data);
    }

    $this->createDocument();
  }

  protected function importFile($data)
  {
    $dataMerchants = $data['report']['merchants']['merchant'];
    foreach ($dataMerchants as $merchant)
    {
      foreach ($merchant['terminals']['terminal'] as $terminal)
      {
        $ct = [
          'brutto_ac' => floatval($terminal['card_total']['sum_brutto_account_currency']),
          'brutto_tc' => floatval($terminal['card_total']['sum_brutto_transaction_currency']),
          'fee' => floatval($terminal['card_total']['sum_fee']),
          'netto' => floatval($terminal['card_total']['sum_netto']),
          'text' => $terminal['card_total']['asoc'].' '.$terminal['card_total']['num_sum'],
        ];

        $this->trans[] = $ct;
      }
    }
  }

  protected function createDocument()
  {
    $rowOrder = 100;

		$newDoc = new CreateDocumentUtility ($this->app);
		$newDoc->createDocumentHead('cmnbkp');

		$newDoc->docHead['person'] = 93;
    //$newDoc->docHead['dateAccounting'] = $accDate;
    //$newDoc->docHead['dateTax'] = $accDate;
		$newDoc->docHead['author'] = $this->app()->userNdx();
    //$newDoc->docHead['dbCounter'] = $dbCounter;
		$newDoc->docHead['title'] = '	Poplatky z uskutečněných transakcí platebními kartami';

    $sumFee = 0.0;
    foreach ($this->trans as $tr)
    {
      $newRow = [
        'item' => 76,
        'debit' => $tr['fee'],
        'text' => $tr['text'],
      ];

      $newRow['operation'] = '1099998';
      $newRow['rowOrder'] = $rowOrder;

      $newDoc->addDocumentRow ($newRow);

      $rowOrder += 100;
      $sumFee += $tr['fee'];
    }

    $newRow = [
      'item' => 70,
      'credit' => $sumFee,
      'text' => 'Celkem poplatky',
      'symbol1' => '2024',
    ];

    $newRow['operation'] = '1099998';
    $newRow['rowOrder'] = $rowOrder;
    $newDoc->addDocumentRow ($newRow);

    // -- save
		$docNdx = $newDoc->saveDocument(CreateDocumentUtility::sdsConfirmed, intval($this->docNdx));
  }

  public function title()
  {
    return "ČSOB Karektní transakce";
  }
}
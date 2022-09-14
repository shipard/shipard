<?php

namespace e10pro\condo\libs;
use \Shipard\Utils\Json;
use \Shipard\Utils\Utils;
use \e10doc\core\libs\CreateDocumentUtility;


/**
 * class FlatSettlementInvoiceGenerator
 */
class FlatSettlementInvoiceGenerator extends \e10doc\reporting\libs\CalcReportResultDocGenerator
{
  var $workOrderRecData;

  public function setCalcReportResult($calcReportResultNdx)
  {
    parent::setCalcReportResult($calcReportResultNdx);

    $this->workOrderRecData = $this->app()->loadItem($this->calcReportResultRecData['workOrder'], 'e10mnf.core.workOrders');
  }

  public function generateDoc()
  {
		$newDoc = new CreateDocumentUtility ($this->app);

		$newDoc->createDocumentHead('invno');

		$newDoc->docHead['person'] = $this->workOrderRecData['customer'];
    $newDoc->docHead['workOrder'] = $this->workOrderRecData['ndx'];

		$newDoc->docHead['datePeriodBegin'] = $this->calcReportRecData['dateBegin'];//$this->periodBegin;
		$newDoc->docHead['datePeriodEnd'] = $this->calcReportRecData['dateEnd'];
		$newDoc->docHead['dateAccounting'] = $this->calcReportRecData['dateEnd'];
		$newDoc->docHead['dateTax'] = $this->calcReportRecData['dateEnd'];

    $author = intval($this->app()->cfgItem ('options.e10doc-sale.author', 0));
		$newDoc->docHead['author'] = $author;

		$newDoc->docHead['dateDue'] = Utils::today();
		$dd = intval($this->calcReportCfgSettings['invoiceDueDays'] ?? 0);
		if ($dd === 0)
			$dd = intval($this->app()->cfgItem ('options.e10doc-sale.dueDays', 14));
		if (!$dd)
			$dd = 14;
		$newDoc->docHead ['dateDue']->add (new \DateInterval('P'.$dd.'D'));

    $newDoc->docHead['dbCounter'] = intval($this->calcReportCfgSettings['dbCounterInvoiceOut']);


    $this->addDocRow($newDoc, 'acc_item_advance_water_cold', 'res_flat_water_cold_balance');
    $this->addDocRow($newDoc, 'acc_item_advance_water_cold_warm', 'res_flat_water_warm_cold_balance');
    $this->addDocRow($newDoc, 'acc_item_advance_water_heating', 'res_flat_water_heating_balance');
    $this->addDocRow($newDoc, 'acc_item_advance_electricity_common', 'res_flat_electricity_common_balance');
    $this->addDocRow($newDoc, 'acc_item_advance_insurance', 'res_flat_insurance_balance');
    $this->addDocRow($newDoc, 'acc_item_advance_administration', 'res_flat_administration_balance');

		$newDoc->docHead['title'] = $this->calcReportRecData['title'].' / '.$this->workOrderRecData['title'];

		$invoiceNdx = $newDoc->saveDocument(CreateDocumentUtility::sdsConfirmed, intval($this->calcReportResultRecData['docInvoiceOut']));

    $this->db()->query('UPDATE [e10doc_reporting_calcReportsResults] SET [docInvoiceOut] = %i', $invoiceNdx, ' WHERE [ndx] = %i', $this->calcReportResultNdx);
  }

  public function addDocRow(CreateDocumentUtility $newDoc, $itemId, $resNumberId)
  {
    if ($this->calcReportResultResultData[$resNumberId])
    {
      $newRow = $newDoc->createDocumentRow();
      $newRow['item'] = $this->calcReportCfgSettings[$itemId];
      $itemRecData = $this->app()->loadItem($newRow['item'], 'e10.witems.items');
      $newRow['priceItem'] = - $this->calcReportResultResultData[$resNumberId];
      $newRow['operation'] = '1099998';
      $newRow['text'] = $itemRecData['fullName'];
      $newRow['symbol1'] = $this->workOrderRecData['symbol1'];
      $newRow['symbol2'] = $itemRecData['id'].'-'.$this->calcReportRecData['dateBegin']->format('y');
      $newDoc->addDocumentRow ($newRow);
    }
  }
}

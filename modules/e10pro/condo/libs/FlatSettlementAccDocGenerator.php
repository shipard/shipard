<?php

namespace e10pro\condo\libs;
use \Shipard\Utils\Json;
use \Shipard\Utils\Utils;
use \e10doc\core\libs\CreateDocumentUtility;


/**
 * class FlatSettlementAccDocGenerator
 */
class FlatSettlementAccDocGenerator extends \e10doc\reporting\libs\CalcReportResultDocGenerator
{
  var $workOrderRecData;
  var $rowOrder = 100;

  public function setCalcReportResult($calcReportResultNdx)
  {
    parent::setCalcReportResult($calcReportResultNdx);

    $this->workOrderRecData = $this->app()->loadItem($this->calcReportResultRecData['workOrder'], 'e10mnf.core.workOrders');
  }

  public function generateDoc()
  {
		$newDoc = new CreateDocumentUtility ($this->app);

		$newDoc->createDocumentHead('cmnbkp');

		$newDoc->docHead['person'] = $this->workOrderRecData['customer'];
    $newDoc->docHead['workOrder'] = $this->workOrderRecData['ndx'];
		$newDoc->docHead['dateAccounting'] = $this->calcReportRecData['dateEnd'];
		$newDoc->docHead['dateTax'] = $this->calcReportRecData['dateEnd'];

    $author = intval($this->app()->cfgItem ('options.e10doc-sale.author', 0));
		$newDoc->docHead['author'] = $author;

    $newDoc->docHead['dbCounter'] = intval($this->calcReportCfgSettings['dbCounterAccDoc']);
		$newDoc->docHead['title'] = $this->calcReportRecData['title'].' / '.$this->workOrderRecData['title'];

    $this->calcReportResultResultData['res_flat_water_cold_cost'] ??= 0.0;
    $this->calcReportResultResultData['res_flat_water_warm_cold_cost'] ?? 0.0;
    $this->calcReportResultResultData['res_flat_water_heating_cost'] ??= 0.0;
    $this->calcReportResultResultData['res_flat_electricity_common_cost'] ??= 0.0;
    $this->calcReportResultResultData['res_flat_insurance_cost'] ??= 0.0;
    $this->calcReportResultResultData['res_flat_administration_cost'] ??= 0.0;

    $this->addDocRowDebit($newDoc, 'acc_item_advance_water_cold', 'res_flat_water_cold_cost');
    $this->addDocRowDebit($newDoc, 'acc_item_advance_water_cold_warm', 'res_flat_water_warm_cold_cost');
    $this->addDocRowDebit($newDoc, 'acc_item_advance_water_heating', 'res_flat_water_heating_cost');
    $this->addDocRowDebit($newDoc, 'acc_item_advance_electricity_common', 'res_flat_electricity_common_cost');
    $this->addDocRowDebit($newDoc, 'acc_item_advance_insurance', 'res_flat_insurance_cost');
    $this->addDocRowDebit($newDoc, 'acc_item_advance_administration', 'res_flat_administration_cost');

    $this->addDocRowCredit($newDoc, 'acc_item_water_profit', $this->calcReportResultResultData['res_flat_water_cold_cost'] + $this->calcReportResultResultData['res_flat_water_warm_cold_cost']);
    $this->addDocRowCredit($newDoc, 'acc_item_water_heating_profit', $this->calcReportResultResultData['res_flat_water_heating_cost']);
    $this->addDocRowCredit($newDoc, 'acc_item_electricity_profit', $this->calcReportResultResultData['res_flat_electricity_common_cost']);
    $this->addDocRowCredit($newDoc, 'acc_item_insurance_profit', $this->calcReportResultResultData['res_flat_insurance_cost']);
    $this->addDocRowCredit($newDoc, 'acc_item_administration_profit', $this->calcReportResultResultData['res_flat_administration_cost']);

		$docNdx = $newDoc->saveDocument(CreateDocumentUtility::sdsConfirmed, intval($this->calcReportResultRecData['docAcc']));

    $this->db()->query('UPDATE [e10doc_reporting_calcReportsResults] SET [docAcc] = %i', $docNdx, ' WHERE [ndx] = %i', $this->calcReportResultNdx);
  }

  public function addDocRowDebit(CreateDocumentUtility $newDoc, $itemId, $resNumberId)
  {
    if ($this->calcReportResultResultData[$resNumberId] ?? 0)
    {
      $newRow = $newDoc->createDocumentRow();
      $newRow['item'] = $this->calcReportCfgSettings[$itemId];
      $itemRecData = $this->app()->loadItem($newRow['item'], 'e10.witems.items');
      $newRow['debit'] = $this->calcReportResultResultData[$resNumberId];
      $newRow['operation'] = '1099998';
      $newRow['text'] = $itemRecData['fullName'];
      $newRow['symbol1'] = $this->workOrderRecData['symbol1'];
      $newRow['symbol2'] = $itemRecData['id'].'-'.$this->calcReportRecData['dateBegin']->format('y');
      $newRow['person'] = $this->workOrderRecData['customer'];
      $newRow['rowOrder'] = $this->rowOrder;

      $this->rowOrder += 100;

      $newDoc->addDocumentRow ($newRow);
    }
  }

  public function addDocRowCredit(CreateDocumentUtility $newDoc, $itemId, $amount)
  {
    if ($amount)
    {
      $newRow = $newDoc->createDocumentRow();
      $newRow['item'] = intval($this->calcReportCfgSettings[$itemId] ?? 0);
      $itemRecData = $this->app()->loadItem($newRow['item'], 'e10.witems.items');
      $newRow['credit'] = $amount;
      $newRow['operation'] = '1099998';
      $newRow['text'] = $itemRecData['fullName'] ?? '';
      $newRow['rowOrder'] = $this->rowOrder;

      $this->rowOrder += 100;

      $newDoc->addDocumentRow ($newRow);
    }
  }
}

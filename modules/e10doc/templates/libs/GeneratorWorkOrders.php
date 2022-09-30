<?php

namespace e10doc\templates\libs;
use e10doc\core\e10utils, \Shipard\Utils\Str, e10doc\core\CreateDocumentUtility;
use e10doc\templates\TableHeads;

/**
 * Class GeneratorWorkOrders
 */
class GeneratorWorkOrders extends \e10doc\templates\libs\Generator
{
	public function createInvoiceHead ($workOrderRecData)
	{
    $linkId = 'TMPL:'.$this->templateNdx.';WO:'.$workOrderRecData['ndx'].'P:'.$this->scheduler->periodBegin->format('Ymd').'.'.$this->scheduler->periodEnd->format('Ymd').';';
		$this->invHead = [
      'docType' => $this->templateRecData['dstDocType'],
      'template' => $this->templateRecData['ndx'],
      'linkId' => $linkId,
    ];
		$this->tableDocsHeads->checkNewRec($this->invHead);

		$this->invHead ['docKind'] = $this->templateRecData['dstDocKind'];
		$this->invHead ['datePeriodBegin'] = $this->scheduler->periodBegin;
		$this->invHead ['datePeriodEnd'] = $this->scheduler->periodEnd;
		$this->invHead ['person'] = $workOrderRecData['customer'];
		$this->invHead ['title'] = $workOrderRecData['title'];
		$this->invHead ['dateIssue'] = $this->scheduler->today;
		$this->invHead ['dateAccounting'] = $this->scheduler->dateAccounting;
		$this->invHead ['dateTax'] = $this->scheduler->dateAccounting;
		$this->invHead ['dateDue'] = new \DateTime ($this->invHead ['dateTax']->format('Y-m-d'));

    if ($workOrderRecData['symbol1'] !== '')
      $this->invHead ['symbol1'] = $workOrderRecData['symbol1'];

		$dd = $this->templateRecData['dueDays'];
		if ($dd === 0)
			$dd = intval($this->app()->cfgItem ('options.e10doc-sale.dueDays', 14));
		if (!$dd)
			$dd = 14;
		$this->invHead ['dateDue']->add (new \DateInterval('P'.$dd.'D'));

		$this->invHead ['currency'] = $this->templateRecData['currency'];
		$this->invHead ['paymentMethod'] = $this->templateRecData['paymentMethod'];
		if ($this->templateRecData['myBankAccount'])
			$this->invHead ['myBankAccount'] = $this->templateRecData['myBankAccount'];

		if ($this->templateRecData['taxCalc'] == 0) // price is without VAT
			$this->invHead ['taxCalc'] = 1;
		else // including VAT
			$this->invHead ['taxCalc'] = e10utils::taxCalcIncludingVATCode($this->app(), $this->invHead ['dateAccounting']);

		$this->invHead ['automaticRound'] = intval($this->app()->cfgItem ('options.e10doc-sale.automaticRoundOnSale', 0));
		$this->invHead ['roundMethod'] = intval($this->app()->cfgItem ('options.e10doc-sale.roundInvoice', 0));
		$this->invHead ['author'] = intval($this->app()->cfgItem ('options.e10doc-sale.author', 0));

		$this->invHead ['centre'] = $this->templateRecData['centre'];
		$this->invHead ['wkfProject'] = $this->templateRecData['wkfProject'];
		$this->invHead ['workOrder'] = $workOrderRecData['ndx'];

    $this->invHead ['dbCounter'] = $this->templateRecData['dstDbCounter'];

    $this->variables->setDataItem('docHead', $this->invHead);
    $this->variables->setDataItem('woHead', $workOrderRecData);

    // -- resolve variables
    $this->docNote = $this->variables->resolve($this->templateRecData['docNote']);

    $tt = trim($this->variables->resolve($this->templateRecData['docText']));
    if ($tt !== '')
      $this->invHead ['title'] = Str::upToLen($tt, 120);

    $tt = trim($this->variables->resolve($this->templateRecData['headSymbol1']));
    if ($tt !== '')
      $this->invHead ['symbol1'] = Str::upToLen($tt, 20);

    $tt = trim($this->variables->resolve($this->templateRecData['headSymbol2']));
    if ($tt !== '')
      $this->invHead ['symbol2'] = Str::upToLen($tt, 20);

		if ($this->scheduler->debug === 2)
		{
			if (!$this->scheduler->cntGeneratedDocs)
				echo "\n    | begin        end        | dateIssue  | dateAcc    | dateDue\n";
			//            - 2021-04-01 - 2021-04-30 | 2021-02-05 | 2021-04-01 | 2021-04-01
			echo "    - ".$this->invHead ['datePeriodBegin']->format('Y-m-d').' - '.$this->invHead ['datePeriodEnd']->format('Y-m-d');
			echo " | ".$this->invHead ['dateIssue']->format('Y-m-d')." | ".$this->invHead ['dateAccounting']->format('Y-m-d');
			echo " | ".$this->invHead ['dateDue']->format('Y-m-d');
			echo "\n";
		}

		$this->invRows = [];
		$this->scheduler->cntGeneratedDocs++;
	}

  protected function createInvoiceRows($workOrderRecData)
  {
    $qr = [];
    array_push($qr, 'SELECT woRows.* ');
    array_push($qr, ' FROM [e10mnf_core_workOrdersRows] AS woRows');
    array_push($qr, ' WHERE workOrder = %i', $workOrderRecData['ndx']);
    array_push($qr, ' AND ([validFrom] IS NULL OR [validFrom] <= %d', $this->invHead ['dateAccounting'],')',
                    ' AND ([validTo] IS NULL OR [validTo] >= %d)', $this->invHead ['dateAccounting']);
    array_push($qr, ' ORDER BY [rowOrder], [ndx]');

    $rows = $this->db()->query ($qr);
    forEach ($rows as $row)
    {
      $r = [];
      $this->tableDocsRows->checkNewRec($r);

      $r['item'] = $row['item'];
      $r['text'] = $row['text'];
      $r['quantity'] = $row['quantity'];
      $r['unit'] = $row['unit'];
      $r['priceItem'] = $row['priceItem'];
      $r['rowOrder'] = (count($this->invRows) + 1) * 100;

      $item = $this->tableDocsRows->loadItem ($row['item'], 'e10_witems_items');
      if ($item) // TODO: log invalid item
        $r['taxCode'] = $this->tableDocsHeads->taxCode (1, $this->invHead, $item['vatRate']);

      if ($item['itemKind'] == 2 && $this->invHead ['docType'] === 'invno')
        $r['operation'] = 1099998;

      $this->variables->setDataItem('docRow', $r);
      $this->variables->setDataItem('docRowItem', $item);

      if ($item['useBalance'])
      {
        $tt = trim($this->variables->resolve($this->templateRecData['rowsSymbol1']));
        if ($tt !== '')
          $r ['symbol1'] = Str::upToLen($tt, 20);
        $tt = trim($this->variables->resolve($this->templateRecData['rowsSymbol2']));
        if ($tt !== '')
          $r ['symbol2'] = Str::upToLen($tt, 20);
      }

      $this->invRows[] = $r;
    }
  }

  protected function doWorkOrder($workOrderRecData)
  {
    $this->scheduler->reviewData [$this->scheduler->dateId]['templates'][$this->templateNdx]['cntDocs']++;

    if ($this->scheduler->save)
    {
      $this->createInvoiceHead($workOrderRecData);
      $this->createInvoiceRows($workOrderRecData);

      $docNdx = $this->saveDocument($this->templateRecData);

      if ($docNdx && $this->templateRecData['dstDocAutoSend'] !== TableHeads::asmNone)
        $this->send($docNdx);
    }
  }

  protected function doWorkOrders()
  {
    if ($this->scheduler->debug)
    {
       echo "WO:";
    }

    $q = [];
    array_push ($q, 'SELECT [wo].*');
    array_push ($q, ' FROM [e10mnf_core_workOrders] AS [wo]');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [wo].[docKind] = %i', $this->templateRecData['srcWorkOrderKind']);
    array_push ($q, ' ORDER BY [wo].[docNumber]');
    array_push ($q, ' LIMIT 500');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
       if ($this->scheduler->debug)
       {
          echo " ".$r['docNumber'];
       }

       $this->doWorkOrder($r->toArray());
    }

    if ($this->scheduler->debug)
    {
       echo "; ";
    }
  }

  public function run()
  {
    $this->doWorkOrders();
  }
}

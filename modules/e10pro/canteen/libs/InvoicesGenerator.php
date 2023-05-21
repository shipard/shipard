<?php

namespace e10pro\canteen\libs;
use \e10\utils;


/**
 * Class InvoicesGenerator
 * @package e10pro\canteen\libs
 */
class InvoicesGenerator extends \Shipard\Base\Utility
{
	var $year = 0;
	var $month = 0;
	var $periodBegin = NULL;
	var $periodEnd = NULL;

	var $canteenNdx = 0;
	var $canteenCfg = 0;

	function doCanteen()
	{
		$report = new \e10pro\canteen\reports\Persons($this->app());
		$report->canteenNdx = $this->canteenNdx;
		$report->periodBegin = $this->periodBegin;
		$report->periodEnd = $this->periodEnd;

		$report->createPdf();
		$peoplesData = $report->peoplesData;
		$relations =  $report->relations;
		unset($report);

		foreach ($peoplesData as $relId => $rel)
		{
			$relCfg = $relations[$relId];
			echo " - relation: `{$relId}` - ".$relCfg['name']."\n";

			$this->invoicesIndividual(/*$report, */$relId, $relCfg, $rel);
		}
	}

	protected function invoicesIndividual(/*$report, */$relationId, $relationCfg, $relationData)
	{
		$itemMF = $this->canteenCfg['itemMainFood'];
		$itemAF = $this->canteenCfg['itemMainFood'];
		foreach ($relationData as $personNdx => $personInfo)
		{
			if (!isset($personInfo['detail']))
				continue;

			echo '   * '.$personInfo['personName'];
			$invoiceData = [
				'personNdx' => $personNdx, 'personName' => $personInfo['personName'],
				'rows' => []
			];

			$linkId = 'cntn-'.$this->canteenNdx.'-'.$relationId.'-'.$this->periodBegin->format('y-m');
			echo " / ".$linkId;
			$invoiceExist = $this->db()->query('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', 'invno',
						' AND [person] = %i', $personNdx, ' AND [linkId] = %s', $linkId)->fetch();
			if ($invoiceExist)
			{
				echo "; faktura existuje: ".$invoiceExist['docNumber']."\n";
				continue;
			}

			echo "\n";

			if (isset($personInfo['detail']['main']))
			{
				foreach ($personInfo['detail']['main'] as $pf)
				{
					if (!$pf['count'])
						continue;
					$row = ['itemNdx' => $itemMF, 'text' => $this->canteenCfg['mainFoodTitle'], 'priceItem' => $pf['priceItem'], 'quantity' => $pf['count'], 'unit' => 'pcs'];
					$invoiceData['rows'][] = $row;
				}
			}
			if (isset($this->canteenCfg['addFoods']))
			{
				foreach ($this->canteenCfg['addFoods'] as $afNdx => $af)
				{
					$afId = 'af_' . $afNdx;
					if (!isset($personInfo['detail'][$afId]))
						continue;
					foreach ($personInfo['detail'][$afId] as $priceId => $pf)
					{
						$row = ['itemNdx' => $itemAF, 'text' => $af['fn'], 'priceItem' => $pf['priceItem'], 'quantity' => $pf['count'], 'unit' => 'pcs'];
						$invoiceData['rows'][] = $row;
					}
				}
			}

			//echo '   - '.json_encode($invoiceData)."\n";
			$invoiceNdx = $this->saveInvoice($invoiceData, $relationId);

			$personReport = new \e10pro\canteen\reports\Persons($this->app());
			$personReport->canteenNdx = $this->canteenNdx;
			$personReport->periodBegin = $this->periodBegin;
			$personReport->periodEnd = $this->periodEnd;

			$personReport->onePerson = $personNdx;
			$personReport->onePersonRelationId = $relationId;
			$personReport->subReportId = 'peoples';
			$personReport->createPdf();

			$attTitle = 'Vyúčtovaní stravného '.$this->periodBegin->format('Y / m');
			$attNdx = \E10\Base\addAttachments ($this->app, 'e10doc.core.heads', $invoiceNdx, $personReport->fullFileName, '', true, 10000, $attTitle);
			$newLink = [
				'linkId' => 'e10docs-send-atts',
				'srcTableId' => 'e10doc.core.heads', 'srcRecId' => $invoiceNdx,
				'dstTableId' => 'e10.base.attachments', 'dstRecId' => $attNdx
			];
			$this->db()->query ('INSERT INTO e10_base_doclinks ', $newLink);

			unset($personReport);
		}
	}

	protected function saveInvoice ($invoiceData, $relationId)
	{
		$newDoc = new \E10Doc\Core\CreateDocumentUtility ($this->app);
		$newDoc->createDocumentHead('invno');
		$newDoc->docHead['docKind'] = $this->canteenCfg['dstDocKind'];
		$newDoc->docHead['person'] = $invoiceData['personNdx'];

		$newDoc->docHead['datePeriodBegin'] = $this->periodBegin;
		$newDoc->docHead['datePeriodEnd'] = $this->periodEnd;
		$newDoc->docHead['dateAccounting'] = $this->periodEnd;
		$newDoc->docHead['dateTax'] = $this->periodEnd;

		$author = $this->canteenCfg['invoiceAuthor'];
		if (!$author)
			$author = intval($this->app()->cfgItem ('options.e10doc-sale.author', 0));
		$newDoc->docHead['author'] = $author;

		$newDoc->docHead['dateDue'] = new \DateTime ($newDoc->docHead ['dateTax']->format('Y-m-d'));
		$dd = intval($this->canteenCfg['dueDays']);
		if ($dd === 0)
			$dd = intval($this->app()->cfgItem ('options.e10doc-sale.dueDays', 14));
		if (!$dd)
			$dd = 14;
		$newDoc->docHead ['dateDue']->add (new \DateInterval('P'.$dd.'D'));

		$newDoc->docHead['linkId'] = 'cntn-'.$this->canteenNdx.'-'.$relationId.'-'.$this->periodBegin->format('y-m');

		// -- dbCounter
		$dbCounters = $this->app->cfgItem ('e10.docs.dbCounters.'.$newDoc->docHead ['docType'], FALSE);
		if ($dbCounters !== FALSE)
			$newDoc->docHead ['dbCounter'] = intval(key($dbCounters));

		//echo "SAVE: ".json_encode($newDoc->docHead)."\n\n";

		forEach ($invoiceData['rows'] as $r)
		{
			$newRow = $newDoc->createDocumentRow($r);
			$newRow['item'] = $r['itemNdx'];
			$newRow['quantity'] = $r['quantity'];
			$newRow['priceItem'] = $r['priceItem'];
			$newRow['operation'] = '1010001';
			$newRow['text'] = $r['text'];

			$newDoc->addDocumentRow ($newRow);
		}
		$newDoc->docHead['title'] = 'Vyúčtování stravného '.$this->periodBegin->format('Y / m').': '.$this->canteenCfg['fn'];

		$this->db()->begin();
		$newDoc->saveDocument($this->canteenCfg['dstDocState']);
		$this->db()->commit();

		$newDocNdx = $newDoc->docHead['ndx'];
		unset($newDoc);

		return $newDocNdx;
	}

	public function run()
	{
		$this->periodBegin = utils::createDateTime(sprintf('%4d-%02d-01', $this->year, $this->month));
		if (!$this->periodBegin)
		{
			echo "Invalid date\n";
			return;
		}
		$this->periodEnd = utils::createDateTime(sprintf('%4d-%02d-', $this->year, $this->month).$this->periodBegin->format('t'));

		//echo "date begin: ".$this->periodBegin->format('Y-m-d')."\n";
		//echo "date end: ".$this->periodEnd->format('Y-m-d')."\n";

		$canteens = $this->app->cfgItem ('e10pro.canteen.canteens', []);
		foreach ($canteens as $canteenNdx => $canteen)
		{
			echo "# canteen: ".$canteen['fn']."\n";
			$this->canteenNdx = $canteenNdx;
			$this->canteenCfg = $canteen;

			if (!isset($this->canteenCfg['invoicingEnabled']) || !$this->canteenCfg['invoicingEnabled'])
				continue;

			$this->doCanteen();
		}
	}
}

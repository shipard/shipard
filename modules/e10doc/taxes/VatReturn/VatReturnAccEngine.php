<?php

namespace e10doc\taxes\VatReturn;

use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils;


/**
 * class VatReturnAccEngine
 */
class VatReturnAccEngine extends Utility
{
  var int $taxReportNdx = 0;
  var int $taxFillingNdx = 0;
	var ?array $fillingRecData = NULL;
	var ?array $reportRecData = NULL;
	var ?array $taxRegCfg = NULL;

	var $vatPeriodNdx;
	var $vatPeriod;
	var $tableDocs;
	var $tableRows;
	var \Shipard\Table\DbTable $tableTaxReports;
	var \Shipard\Table\DbTable $tableTaxFilings;

	var $taxReturnDocNdx = 0;

	var $closeDocument = FALSE;
	var $taxOfficeNdx = 0;
	var $dateIssue = NULL;

	function createDocHead ()
	{
		// dbcounter id
		$dbCounter = $this->db()->query ('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s AND [activitiesGroup] = %s',
																		 'cmnbkp', 'tax')->fetch();
		if (!isset ($dbCounter['ndx']))
		{
			error_log ("ERROR - VATReturnEngine: dbCounter not found.");
			return FALSE;
		}

		$docDate = $this->vatPeriod['end']->format ('Y-m-d');

		$existedDoc = NULL;
		if ($this->reportRecData['accDocument'])
		{
			$existedDoc = $this->tableDocs->loadItem($this->reportRecData['accDocument']);
		}

		//if ($existedDoc['docState'] === 1000 || $existedDocs['docState'] === 1200 || $existedDocs['docState'] === 8000)
		if ($existedDoc)
		{
			$docH = $existedDoc;
		}
		else
		{
			$docH = [];
			$docH ['docType'] = 'cmnbkp';
			$this->tableDocs->checkNewRec ($docH);
		}

		// docKind
		$docKinds = $this->app->cfgItem ('e10.docs.kinds', FALSE);
		$dk = utils::searchArray($docKinds, 'activity', 'taxVatReturn');

		$title = $this->reportRecData['title'];

		$docH ['taxPeriod']					= $this->vatPeriodNdx;
		$docH ['dateAccounting']		= $docDate;
		$docH ['title'] 						= $title;
		$docH ['taxCalc']						= 0;
		$docH ['dbCounter']					= $dbCounter['ndx'];
		$docH ['docKind']						= $dk['ndx'];
		$docH ['linkId']						= 'accTaxReport;'.$this->reportRecData['ndx'].';'.$this->fillingRecData['ndx'];

		if ($this->taxOfficeNdx)
			$docH ['person'] = $this->taxOfficeNdx;
		if ($this->dateIssue)
			$docH ['dateIssue'] = $this->dateIssue;

		return $docH;
	}

	function createDocRows ($head)
	{
		$vatReport = new \e10doc\taxes\VatReturn\VatReturnReport ($this->app);
		$vatReport->taxPeriod = $this->vatPeriodNdx;
		$vatReport->taxReportNdx = $this->taxReportNdx;
		$vatReport->filingNdx = $this->taxFillingNdx;
		$vatReport->init();

		$vatReport->loadData();

		$taxHomeCountry = E10Utils::docTaxHomeCountryId($this->app(), $this->docHead);
		$taxRegCountry = $this->taxRegCfg['taxCountry'];

		$newRows = [];
		$vatInput = 0;
		$vatOutput = 0;
		$vatInputR = 0;
		$vatOutputR = 0;

		foreach ($vatReport->tcSums as $dir => $dirContent)
		{
			forEach ($dirContent as $tcId => $r)
			{
				if (!isset($r['sumTax']) || $r['sumTax'] == 0)
					continue;
				if (!isset($r['taxCode']) || $r['taxCode'] === '')
					continue;

				$taxCodeCfg = $vatReport->taxCodes[$tcId];
				$newRow = [];
				$newRow ['operation'] = 1099999;

				if ($taxHomeCountry === $taxRegCountry && $this->taxRegCfg['payerKind'] === 0)
					$newRow ['debsAccountId'] = '343'.substr($r['taxCode'], 4);
				else
					$newRow ['debsAccountId'] = '343'.substr($r['taxCode'], 2);	

				$newRow ['item'] = 0;
				$newRow ['text'] = $taxCodeCfg['fullName'];
				$newRow ['quantity'] = 1;
				$newRow ['priceItem'] = 0;

				$newRow ['credit'] = 0.0;
				$newRow ['debit'] = 0.0;

				if ($dir === 0)
					$newRow ['credit'] = $r['sumTax'];
				else
					$newRow ['debit'] = $r['sumTax'];

				$vatInput += $newRow ['credit'];
				$vatOutput += $newRow ['debit'];

				$vatInputR += round ($r['credit']);
				$vatOutputR += round ($r['debit']);

				$newRows[] = $newRow;
			}
		}
		
		$vatAmount = $vatOutput - $vatInput;
		$vatAmountR = $vatReport->totalTaxVatReturnAmount;
		if ($vatAmountR > 0)
		{
			$newRow = ['operation' => 1099998, 'debit' => 0.0, 'credit' => $vatAmountR, 'text' => 'Odvod DPH '.$this->vatPeriod['fullName']];
			$newRow = array_merge($newRow, $this->findRowData('odvod-dph', $head['owner'], $head['person']));
			$newRows[] = $newRow;
		}
		if ($vatAmountR < 0)
		{
			$newRow = ['operation' => 1099998, 'credit' => 0.0, 'debit' => - $vatAmountR, 'text' => 'Odpočet DPH '.$this->vatPeriod['fullName']];
			$newRow = array_merge ($newRow, $this->findRowData('odpocet-dph', $head['owner'], $head['person']));
			$newRows[] = $newRow;
		}

		$amountRounding = $vatAmountR - $vatAmount;
		if ($amountRounding < 0)
		{
			$newRow = ['operation' => 1099998, 'debit' => 0.0, 'credit' => -$amountRounding, 'text' => 'Zaokrouhlení'];
			$newRow = array_merge ($newRow, $this->findRowData('zaokrouhleni-vynosy', $head['owner'], $head['person']));
			$newRows[] = $newRow;
		}
		if ($amountRounding > 0)
		{
			$newRow = ['operation' => 1099998, 'credit' => 0.0, 'debit' => $amountRounding, 'text' => 'Zaokrouhlení'];
			$newRow = array_merge ($newRow, $this->findRowData('zaokrouhleni-naklady', $head['owner'], $head['person']));
			$newRows[] = $newRow;
		}
		
		$allDocRows = ['docRows' => $newRows, 'taxRows' => $newTaxRows];
		return $allDocRows;
	}

	public function setParams(int $filingNdx)
	{
		$this->tableTaxReports = $this->app()->table('e10doc.taxes.reports');
		$this->tableTaxFilings = $this->app()->table('e10doc.taxes.filings');
		
		$this->taxFillingNdx = $filingNdx;
		$this->fillingRecData = $this->tableTaxFilings->loadItem($this->taxFillingNdx);

		if (!$this->fillingRecData)
			return;

		$this->taxReportNdx = $this->fillingRecData['report'];
		$this->reportRecData = $this->tableTaxReports->loadItem($this->taxReportNdx);
		$this->taxRegCfg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$this->reportRecData['taxReg'], NULL);
		if ($this->taxRegCfg)
			$this->taxOfficeNdx = $this->taxRegCfg['taxOffice'];

		$this->vatPeriodNdx = intval($this->reportRecData['taxPeriod']);
		$this->vatPeriod = $this->app()->loadItem ($this->vatPeriodNdx, 'e10doc.base.taxperiods');
	}

	function run ()
	{
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);

		$this->app->db->begin();

		$docHead = $this->createDocHead ();
		if ($docHead !== FALSE)
		{
			$docRows = $this->createDocRows($docHead);
			$this->save ($docHead, $docRows);
		}

		$this->app->db->commit();
	}

	protected function save ($head, $rows)
	{
		if (!isset ($head['ndx']))
		{
			$docNdx = $this->tableDocs->dbInsertRec ($head);
		}
		else
		{
			$docNdx = $head['ndx'];
			$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
			$this->tableDocs->dbUpdateRec ($head);
		}

		$this->taxReturnDocNdx = $docNdx;

		$f = $this->tableDocs->getTableForm ('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec ($f->recData);

		forEach ($rows['docRows'] as $docRow)
		{
			$docRow['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($docRow, $f->recData);
		}

		if ($this->closeDocument)
		{
			$f->recData ['docState'] = 4000;
			$f->recData ['docStateMain'] = 2;
			$this->tableDocs->checkDocumentState($f->recData);
		}

		$f->checkAfterSave();
		$this->tableDocs->dbUpdateRec ($f->recData);
		$this->tableDocs->checkAfterSave2 ($f->recData);

		if (!$this->reportRecData['accDocument'])
		{
			$this->db()->query ('UPDATE [e10doc_taxes_reports] SET [accDocument] = %i', $docNdx, ' WHERE [ndx] = %i', $this->reportRecData['ndx']);
		}
	}

	function findRowData ($id, $ownerNdx, $personHead)
	{
		$rowData = array ();

		$rowData['item'] = 0;
		$item = $this->app->db()->fetch('SELECT [ndx] FROM [e10_witems_items] WHERE id = %s LIMIT 1', $id);
		if (isset($item['ndx']) && ($item['ndx'] > 0))
			$rowData['item'] = $item['ndx'];

		if ($id == 'odvod-dph' || $id == 'odpocet-dph')
		{
			$dateDue = new \DateTime($this->vatPeriod['end']);
			if ($id == 'odvod-dph')
				$dateDue->add(new \DateInterval('P25D'));
			else
				$dateDue->add(new \DateInterval('P60D'));
			
			$rowData['dateDue'] = $dateDue;
			$rowData['symbol2'] = '705'.preg_replace("/[^0-9,.]/", "", $this->vatPeriod['id']);
			$rowData = array_merge($rowData, $this->findPrevDocRowData ($rowData['item'], $ownerNdx, $personHead, TRUE));
		}
		else
			$rowData = array_merge($rowData, $this->findPrevDocRowData ($rowData['item'], $ownerNdx, $personHead, FALSE));

		return $rowData;
	}

	function findPrevDocRowData ($itemNdx, $ownerNdx, $personHead, $balanceRow)
	{
		$rowData = ['person' => 0, 'symbol1' => '', 'symbol3' => '', 'bankAccount' => '', 'centre' => 0];

		if ($this->taxOfficeNdx)
		{
			if ($balanceRow)
				$rowData['person'] = $this->taxOfficeNdx;
		}
		else
		{
			$q[] = 'SELECT r.[person] as person, r.[symbol1] as symbol1, r.[symbol3] as symbol3, r.[bankAccount] as bankAccount, r.[centre] as centre';
			array_push ($q, ' FROM [e10doc_core_heads] as h');
			array_push ($q, ' INNER JOIN [e10doc_core_rows] as r ON (h.ndx = r.document)');
			array_push ($q, ' WHERE h.[docState] = %i', 4000);
			array_push ($q, ' AND h.[docType] = %s', 'cmnbkp');
			array_push ($q, ' AND h.[activity] = %s', 'taxVatReturn');
			array_push ($q, ' AND r.[item] = %i', $itemNdx);
			array_push ($q, ' ORDER BY h.[dateAccounting] DESC');
			array_push ($q, ' LIMIT 1');
			$rowPrevDocData = $this->app->db()->fetch($q);
			if (isset($rowPrevDocData))
			{
				$rowData['symbol1'] = $rowPrevDocData['symbol1'];
				$rowData['symbol3'] = $rowPrevDocData['symbol3'];
				$rowData['bankAccount'] = $rowPrevDocData['bankAccount'];
				$rowData['centre'] = $rowPrevDocData['centre'];
				if ($rowPrevDocData['person'] > 0)
					$rowData['person'] = $rowPrevDocData['person'];
				else
				{
					if ($balanceRow)
						$rowData['person'] = $personHead;
				}
			}
		}

		if ($rowData['symbol1'] == '' && $balanceRow)
		{
			$taxid = $this->app->db()->fetch('SELECT [valueString] FROM [e10_base_properties] WHERE ([property]=%s  AND [group]=%s AND [tableid]=%s AND [recid]=%i) LIMIT 1',
				'taxid', 'ids', 'e10.persons.persons', $ownerNdx);
			if (isset($taxid['valueString']) && ($taxid['valueString'] != ''))
				$rowData['symbol1'] = substr($taxid['valueString'], 2);
		}
		if ($rowData['symbol3'] == '' && $balanceRow)
		{
			$rowData['symbol3'] = '1148';
		}

		return $rowData;
	}
}

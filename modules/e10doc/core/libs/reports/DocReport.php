<?php

namespace e10doc\core\libs\reports;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \e10doc\core\libs\reports\DocReportBase;
use \e10doc\core\libs\E10Utils;


/**
 * Class DocReport
 * @package e10doc\core\libs
 */
class DocReport extends DocReportBase
{
	public function loadData()
	{
		parent::loadData();

		$this->data['hasAdvance'] = 0;
		$this->data['hasTaxAdvance'] = 0;
		$this->data['hasNonTaxAdvance'] = 0;
		$this->data['rowsAdvance'] = [];

		$this->data['taxAdvanceSumBase'] = 0;
		$this->data['taxAdvanceSumTax'] = 0;
		$this->data['taxAdvanceSumTotal'] = 0;
		$this->data['taxAdvanceSumBaseHc'] = 0;
		$this->data['taxAdvanceSumTaxHc'] = 0;
		$this->data['taxAdvanceSumTotalHc'] = 0;

		$this->data['nonTaxAdvanceSumBase'] = 0;
		$this->data['nonTaxAdvanceSumTax'] = 0;
		$this->data['nonTaxAdvanceSumTotal'] = 0;
		$this->data['nonTaxAdvanceSumBaseHc'] = 0;
		$this->data['nonTaxAdvanceSumTaxHc'] = 0;
		$this->data['nonTaxAdvanceSumTotalHc'] = 0;

		$cfgTaxCodes = E10Utils::docTaxCodes($this->app(), $this->recData);//$this->app->cfgItem('e10.base.!taxCodes');
		$cfgTaxNotes = E10Utils::docTaxNotes($this->app(), $this->recData);//$this->app->cfgItem('e10.base.taxNotes');

		$usedAdditions = [];
		$usedAdditionsMarksIds = [];

		// -- rows
		$q = [];
		array_push($q, 'SELECT [rows].*, items.fullName as itemFullName, items.id as itemID');
		array_push($q, ' FROM [e10doc_core_rows] as [rows]');
		array_push($q, ' LEFT JOIN e10_witems_items as items ON [rows].item = items.ndx');
		array_push($q, ' WHERE [document] = %i', $this->recData ['ndx']);
		array_push($q, ' AND rowType != %i', 1);
		array_push($q, ' ORDER BY [rows].rowOrder, [rows].ndx');
		$rows = $this->table->db()->query($q);

		$rowNumberAll = 1;

		$tableDocRows = new \E10Doc\Core\TableRows ($this->app);
		foreach ($rows as $row)
		{
			$r = $row->toArray();
			$r ['print'] = $this->getPrintValues($tableDocRows, $r);

			// -- advances
			if ($r['operation'] === 1010101/*1010104*/ && $r['taxCode'] !== '122')
			{ // tax advance
				$r['isAdvance'] = 1;
				$r['isTaxAdvance'] = 1;
				$r['isNonTaxAdvance'] = 0;
				$this->data['hasTaxAdvance'] = 1;

				$this->data['taxAdvanceSumBase'] += $r['taxBase'];
				$this->data['taxAdvanceSumTax'] += $r['tax'];
				$this->data['taxAdvanceSumTotal'] += $r['priceTotal'];
				$this->data['taxAdvanceSumBaseHc'] += $r['taxBaseHc'];
				$this->data['taxAdvanceSumTaxHc'] += $r['taxHc'];
				$this->data['taxAdvanceSumTotalHc'] += $r['priceTotalHc'];
			}
			elseif ($r['operation'] === 1010101)
			{ // non-tax advance
				$r['isAdvance'] = 1;
				$r['isTaxAdvance'] = 0;
				$r['isNonTaxAdvance'] = 1;
				$this->data['hasNonTaxAdvance'] = 1;

				$this->data['nonTaxAdvanceSumBase'] += $r['taxBase'];
				$this->data['nonTaxAdvanceSumTax'] += $r['tax'];
				$this->data['nonTaxAdvanceSumTotal'] += $r['priceTotal'];
				$this->data['nonTaxAdvanceSumBaseHc'] += $r['taxBaseHc'];
				$this->data['nonTaxAdvanceSumTaxHc'] += $r['taxHc'];
				$this->data['nonTaxAdvanceSumTotalHc'] += $r['priceTotalHc'];
			}
			else
			{
				$r['isAdvance'] = 0;
				$r['isTaxAdvance'] = 0;
				$r['isNonTaxAdvance'] = 0;
			}

			// -- addtions / marks
			$adds = $this->table->docAdditionsOur($this->recData, $r);
			if ($adds !== FALSE)
			{
				if (!isset($this->data ['additions']))
				{
					$this->data ['additions'] = [];
				}

				$r['marks'] = [];
				foreach ($adds as $a)
				{
					$r['marks'][] = $a['mark'];
					if (!in_array($a['ndx'], $usedAdditions))
					{
						$usedAdditions[] = $a['ndx'];
						$this->data ['additions'][] = $a;
					}
				}

				$addsMarksId = implode('', $r['marks']);
				if ($addsMarksId !== '')
					$r['additionsMarks'] = $addsMarksId;
			}
			else
				$addsMarksId = '';

			if (!in_array($addsMarksId, $usedAdditionsMarksIds))
				$usedAdditionsMarksIds[] = $addsMarksId;

			$r['rowNumberAll'] = $rowNumberAll;

			if ($r['isAdvance'])
				$this->data['rowsAdvance'][] = $r;

			$rowNumberAll++;
			$this->data ['rows'][] = $r;
		}

		$this->data['taxBaseWithoutAdvances'] = $this->recData['sumBase'] - $this->data['nonTaxAdvanceSumBase'] - $this->data['taxAdvanceSumBase'];
		$this->data['taxBaseWithoutAdvancesHc'] = $this->recData['sumBaseHc'] - $this->data['nonTaxAdvanceSumBaseHc'] - $this->data['taxAdvanceSumBaseHc'];
		$this->data['totalWithoutAdvances'] = $this->recData['sumTotal'] - $this->data['nonTaxAdvanceSumTotal'] - $this->data['taxAdvanceSumTotal'];
		$this->data['totalWithoutAdvancesHc'] = $this->recData['sumTotalHc'] - $this->data['nonTaxAdvanceSumTotalHc'] - $this->data['taxAdvanceSumTotalHc'];

		// -- additions summary
		if (count($usedAdditionsMarksIds) > 1)
			$this->data ['additionsMoreRowsMarks'] = 1;

		if (count($usedAdditions))
		{
			$this->data ['additionsExists'] = 1;
			$this->data ['additions'] = \e10\sortByOneKey($this->data ['additions'], 'mark');
		}

		// -- taxes
		$this->data ['taxNotes'] = [];
		$q = 'SELECT * FROM [e10doc_core_taxes] WHERE [document] = %i ORDER BY [taxPercents] DESC, [taxCode]';
		$rows = $this->table->db()->query($q, $this->recData ['ndx']);

		$tableDocTaxes = new \E10Doc\Core\TableTaxes ($this->app);
		foreach ($rows as $row)
		{
			if (!isset($cfgTaxCodes[$row['taxCode']]))
				continue;
			$taxCode = $cfgTaxCodes[$row['taxCode']];

			$r = $row->toArray();
			$r ['print'] = $this->getPrintValues($tableDocTaxes, $r);
			$r ['print']['taxCode'] = $taxCode['print'];

			if (isset($taxCode['note']))
			{
				$noteId = $taxCode['note'];
				if (!isset($this->data ['taxNotes'][$noteId]))
				{
					$noteMark = strval(count($this->data ['taxNotes']) + 1);
					$this->data ['taxNotes'][$noteId] = ['mark' => $noteMark, 'text' => $cfgTaxNotes[$noteId]['text']];
				}
				else
					$noteMark = $this->data ['taxNotes'][$noteId]['mark'];

				$r['noteId'] = $noteId;
				$r['noteMark'] = $noteMark;
			}
			$this->data ['taxes'][] = $r;
		}
		$this->data ['taxNotes'] = array_values($this->data ['taxNotes']);

		// -- person
		$this->loadData_MainPerson('person');
		$this->loadDataPerson('personHandover');

		// delivery address
		if ($this->recData ['deliveryAddress'] && $this->recData ['deliveryAddress'] !== $this->data ['person']['address']['ndx'])
		{
			$this->data ['deliveryAddress'] = $this->table->loadItem($this->recData ['deliveryAddress'], 'e10_persons_address');
		}

		// -- author
		$this->loadData_Author();

		// -- document owner
		$this->loadData_DocumentOwner();

		$this->data ['myBankAccount'] = $this->table->loadItem($this->recData ['myBankAccount'], 'e10doc_base_bankaccounts');
		if ($this->data ['myBankAccount'] && $this->data ['myBankAccount']['bank'] != 0)
			$this->data ['myBankPerson'] = $this->table->loadItem($this->data ['myBankAccount']['bank'], 'e10_persons_persons');

		// doc properties
		$docProperties = \E10\Base\getPropertiesTable($this->table->app(), 'e10doc.core.heads', $this->recData ['ndx']);
		$this->data ['docs_properties'] = $docProperties;

		// -- ros
		if ($this->recData['rosReg'])
		{
			$rosReg = $this->app()->cfgItem('terminals.ros.regs.' . $this->recData['rosReg'], NULL);
			$rosType = ($rosReg) ? $this->app()->cfgItem('terminals.ros.types.' . $rosReg['rosType'], FALSE) : NULL;

			if ($rosReg && $rosType)
			{
				$rosEngine = $this->app()->createObject($rosType['engine']);
				$rosEngine->loadReportData($this);
			}
		}

		// -- flags
		$this->data ['flags']['foreignCurrency'] = $this->recData ['currency'] !== $this->recData ['homeCurrency'];
		$this->data ['flags']['foreignCountry'] = $this->ownerCountry !== $this->country;
		$this->data ['flags']['partner'] = ($this->recData ['person'] && $this->recData ['person'] != $this->ownerNdx);
		if ($this->data ['flags']['foreignCurrency'] || $this->data ['flags']['foreignCountry'])
			$this->data ['flags']['foreignPayment'] = 1;
		if ($this->recData ['paymentMethod'] === 1)
			$this->data ['flags']['payCash'] = 1;
		else if ($this->recData ['paymentMethod'] === 4)
			$this->data ['flags']['payInvoice'] = 1;
		else if ($this->recData ['paymentMethod'] === 6)
			$this->data ['flags']['payBatch'] = 1;
		else if ($this->recData ['paymentMethod'] === 0)
			$this->data ['flags']['payBankOrder'] = 1;
		else if ($this->recData ['paymentMethod'] === 9)
			$this->data ['flags']['payCheque'] = 1;
		else if ($this->recData ['paymentMethod'] === 10)
			$this->data ['flags']['payPostalOrder'] = 1;

		if ($this->recData ['taxPayer'] && $this->recData ['taxCalc'] != 0)
			$this->data ['flags']['taxDocument'] = 1;
		if (isset ($this->data ['taxes']) && count($this->data ['taxes']))
			$this->data ['flags']['taxes'] = 1;

		// -- document name
		$docType = $this->app()->cfgItem('e10.docs.types.' . $this->recData['docType']);
		$this->data ['documentName'] = $docType['fullName'];

		// accounting
		if ($this->table->accountingDocument($this->recData))
		{
			$acc = $this->table->loadAccounting($this->recData);
			if ($acc)
			{
				if (isset($acc['accRowsHeader']['#']))
					unset($acc['accRowsHeader']['#']);
				$this->data['accounting'] = ['table' => $acc['accRows'], 'header' => $acc['accRowsHeader']];
			}	
		}

		// -- texts
		$tableReportsTexts = $this->app()->table('e10doc.base.reportsTexts');
		$this->data ['reportTexts'] = $tableReportsTexts->loadReportTexts($this->recData, $this->reportMode);

		// -- items codes
		$this->data ['itemCodesHeader'] = [];
		$rowNumber = 1;
		forEach ($this->data ['rows'] as &$row)
		{
			$row ['rowNumber'] = $rowNumber;
			$rowNumber++;
			$row ['rowItemProperties'] = \E10\Base\getPropertiesTable ($this->table->app(), 'e10.witems.items', $row['item']);
			$this->loadDocRowItemsCodes($row);
		}

		if (count($this->data ['itemCodesHeader']))
		{
			forEach ($this->data ['rows'] as &$row)
			{
				$row ['rowItemCodes'] = [];
				foreach ($this->data ['itemCodesHeader'] as $ckNdx => $icData)
					$row ['rowItemCodes'][$ckNdx] = ['itemCodeText' => ''];

				foreach ($row ['rowItemCodesData'] as $ckNdx => $icData)
				{
					$row ['rowItemCodes'][$ckNdx] = $icData;
				}

				$row ['rowItemCodes'] = array_values($row ['rowItemCodes']);
			}
		
			$this->data ['itemCodesHeader'] = array_values($this->data ['itemCodesHeader']);
		}	
	}

	protected function loadDocRowItemsCodes(array &$row)
	{
		$codesKinds = $this->app()->cfgItem('e10.witems.codesKinds', []);

		$q = [];
		array_push ($q, 'SELECT [codes].*');
		array_push ($q, ' FROM [e10_witems_itemCodes] AS [codes]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [codes].[item] = %i', $row['item']);


		$codes = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ckNdx = $r['codeKind'];
			$ck = $codesKinds[$ckNdx];

			if (!isset($this->data ['itemCodesHeader'][$ckNdx]))
			{
				$this->data ['itemCodesHeader'][$ckNdx] = $ck;
			}

			$irc = $r->toArray();
			$codes[$ckNdx] = $irc;
		}
		$row ['rowItemCodesData'] = $codes;
	}

	public function checkDocumentInfo (&$documentInfo)
	{
		$docType = $this->app->cfgItem('e10.docs.types.' . $this->recData['docType']);
		if (isset($docType['outboxDocKind']))
			$documentInfo['messageDocKind'] = $docType['outboxDocKind'];
		if (isset($docType['outboxSystemKind']))
			$documentInfo['outboxSystemKind'] = $docType['outboxSystemKind'];
		if (isset($docType['outboxSystemSection']))
			$documentInfo['outboxSystemSection'] = $docType['outboxSystemSection'];
	}
}

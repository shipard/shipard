<?php

namespace e10doc\core\libs\reports;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \e10doc\core\libs\reports\DocReportBase;
use \e10doc\core\libs\E10Utils;
use \Shipard\UI\Core\UIUtils;
use \Shipard\Utils\Json;


/**
 * Class DocReport
 * @package e10doc\core\libs
 */
class DocReport extends DocReportBase
{
	CONST icmNone = 0, icmSingleLineList = 1, icmSingleLineInline = 2, icmMuliLineCols = 3;
	var $docReportsItemCodesMode = self::icmNone;

	public function loadData()
	{
		$this->testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));

		$this->app()->printMode = TRUE;

		$this->loadData_MainPerson('person');

		parent::loadData();
		if ($this->testNewPersons)
			$this->loadAddresses();

		$this->docReportsItemCodesMode = intval($this->app()->cfgItem ('options.appearanceDocs.docReportsItemCodes', 0));

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
		array_push($q, 'SELECT [rows].*, items.fullName AS itemFullName, items.id AS itemID, items.manufacturerId AS itemManufacturerId, items.description AS itemDecription');
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
			$adds = $this->table->docAdditionsOur($this->recData, $r, $this->sendReportNdx);
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

			// -- rowData / subColumns
			if ($r['rowVds'])
			{
				$sci = $tableDocRows->subColumnsInfo ($r, 'rowData');
				$scData = Json::decode($r['rowData']);
				$scCode = UIUtils::renderSubColumns ($this->app(), $scData, $sci);
				$r['rowDataHtmlCode'] = $scCode;
			}

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

		// -- persons
		$this->loadDataPerson('personHandover');
		$this->loadDataPerson('transportPersonDriver');

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
		/** @var \e10doc\base\TableReportsTexts */
		$tableReportsTexts = $this->app()->table('e10doc.base.reportsTexts');
		$this->data ['reportTexts'] ??= [];
		$tableReportsTexts->loadReportTexts($this->recData, $this->reportMode, $this->data ['reportTexts']);
		if (count($this->data ['reportTexts']))
		{
			$this->data ['_subtemplatesItems'] ??= [];
			if (!count($this->data ['_subtemplatesItems']))
				$this->data ['_subtemplatesItems'][] = 'reportTexts';
			$this->data ['_textRenderItems'] ??= [];
			if (!count($this->data ['_textRenderItems']))
				$this->data ['_textRenderItems'][] = 'reportTexts';
		}

		// -- items codes
		$this->data ['itemCodesHeader'] = [];
		$rowNumber = 1;
		forEach ($this->data ['rows'] as &$row)
		{
			$row ['rowNumber'] = $rowNumber;
			$rowNumber++;
			$row ['rowItemProperties'] = \E10\Base\getPropertiesTable ($this->table->app(), 'e10.witems.items', $row['item']);
			$this->table->loadDocRowItemsCodes($this->recData, $this->data ['person']['personType'], $row, NULL, $row, $this->data);
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
					$ckCfg = $this->app()->cfgItem('e10.witems.codesKinds.'.$ckNdx);
					if (!isset($ckCfg['showInDocRows']) || $ckCfg['showInDocRows'] === 0)
						continue;
					$row ['rowItemCodes'][$ckNdx] = $icData;
				}

				$row ['rowItemCodes'] = array_values($row ['rowItemCodes']);
			}

			$this->data ['itemCodesHeader'] = array_values($this->data ['itemCodesHeader']);
		}

		$this->data ['flags']['multiLineRows'] = 0;
		$this->data ['flags']['itemCodesList'] = 0;
		$this->data ['flags']['itemCodesInline'] = 0;
		if ($this->docReportsItemCodesMode == self::icmMuliLineCols && count($this->data ['itemCodesHeader']))
			$this->data ['flags']['multiLineRows'] = 1;
		if ($this->docReportsItemCodesMode == self::icmSingleLineList && count($this->data ['itemCodesHeader']))
			$this->data ['flags']['itemCodesList'] = 1;
		if ($this->docReportsItemCodesMode == self::icmSingleLineInline && count($this->data ['itemCodesHeader']))
		{
			$this->data ['flags']['itemCodesList'] = 1;
			$this->data ['flags']['itemCodesInline'] = 1;
		}
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

	public function loadAddresses()
	{
		$this->data['personsAddress'] = [];

		if ($this->recData['ownerOffice'])
		{
			$this->data['personsAddress']['ownerOffice'] = $this->loadPersonAddress(0, 0, $this->recData['ownerOffice']);
			$this->data ['flags']['useAddressOwnerOffice'] = 1;
			$this->data ['flags']['usePersonsAddress'] = 1;
		}
		if ($this->recData['deliveryAddress'])
		{
			$this->data['personsAddress']['deliveryAddress'] = $this->loadPersonAddress(0, 0, $this->recData['deliveryAddress']);
			$this->data ['flags']['useAddressPersonDelivery'] = 1;
			$this->data ['flags']['usePersonsAddress'] = 1;
		}
		if ($this->recData['otherAddress1'])
		{
			$this->data['personsAddress']['personOffice'] = $this->loadPersonAddress(0, 0, $this->recData['otherAddress1']);
			$this->data ['flags']['useAddressPersonOffice'] = 1;
			$this->data ['flags']['usePersonsAddress'] = 1;
		}
	}
}

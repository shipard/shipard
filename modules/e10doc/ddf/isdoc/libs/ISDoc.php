<?php

namespace e10doc\ddf\isdoc\libs;
use \e10\json, e10\utils;
use \e10doc\core\libs\E10Utils;

/**
 * Class ISDoc
 * @package e10doc\ddf\libs
 */
class ISDoc extends \e10doc\ddf\core\libs\Core
{
	static $isDocDocTypes = [
		'1' => 'invni', // Faktura - daňový doklad
		'2' => 'invni', // Opravný daňový doklad (dobropis)
		'3' => 'invni', // Opravný daňový doklad (vrubopis)
		'4' => 'invni', // Zálohová faktura (nedaňový zálohový list)
		'5' => 'invni', // Daňový doklad při přijetí platby (daňový zálohový list)
		'6' => 'invni', // Opravný daňový doklad při přijetí platby (dobropis DZL)
		'7' => 'invni', // Zjednodušený daňový doklad
	];

	static $isDocPaymentMethods = [
		'10' => 1, // Hotově
		'20' => 9, // Šekem
		'31' => 7, // Credit Transfer --> Inkasem
		'42' => 0, // Převodním příkazem
		'48' => 2, // Kartou
		'49' => 7, // Direct debit --> Inkasem
		'50' => 3, // Dobírkou
		'97' => 4, // Zaúčtování mezi partnery --> Fakturou
	];

	public function checkFileContent()
	{
		/* <?xml version="1.0" encoding="UTF-8"?>
		 * <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.1">
		 * <Invoice xmlns="http://isdoc.cz/namespace/invoice" version="5.2.3">
		 */

		$tst = strstr($this->fileContent, '<isdoc:Invoice');
		if ($tst)
		{
			$this->fileContent = str_replace('<isdoc:', '<', $this->fileContent);
			$this->fileContent = str_replace('</isdoc:', '</', $this->fileContent);
		}

		$tst = strstr($this->fileContent, '<Invoice');
		if (!$tst)
			return;
		$tst = strstr($this->fileContent, 'http://isdoc.cz/namespace/');
		if (!$tst)
			return;

		$this->ddfId = 1000;

		$this->createImport();

		$this->addFirstContent();
		$this->updateInbox();
	}

	public function createContents()
	{
		$this->createImport();

		$c = [];

		// -- preview
		$ci = [
			'name' => 'Náhled',
			'icon' => 'system/iconPreview',
			'content' => ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->previewCode()]
		];
		$c[] = $ci;

		// -- PDF
		$ci = [
			'name' => 'PDF',
			'icon' => 'system/iconFilePdf',
			'content' => $this->previewAtt(),
		];
		$c[] = $ci;

		// -- xml
		$ci = [
			'name' => 'ISDOC',
			'icon' => 'user/fileText',
			'content' => ['type' => 'text', 'subtype' => 'code', 'text' => $this->ddfRecData['srcData']]
		];
		$c[] = $ci;

		// -- simplifiedData
		$ci = [
			'name' => 'JSON',
			'icon' => 'user/fileText',
			'content' => ['type' => 'text', 'subtype' => 'code', 'text' => json::lint($this->srcImpData)]
		];
		$c[] = $ci;

		// -- impData
		$ci = [
			'name' => 'Shipard',
			'icon' => 'user/fileText',
			'content' => ['type' => 'text', 'subtype' => 'code', 'text' => json::lint($this->impData)]
		];
		$c[] = $ci;

		return $c;
	}

	public function createImport()
	{
		$this->srcImpData = $this->createSrcSimplifiedData();

		$this->importHead();
		$this->importRows();
		$this->addRowsFromSettings();

		$this->impData = ['head' => $this->docHead, 'rows' => $this->docRows];
	}

	protected function importHead()
	{
		$this->docHead['docType'] = 'invni';
		$this->docHead['docState'] = 1000;
		$this->docHead['docStateMain'] = 0;

		$this->checkPersons();
		$this->loadPerson();

		$vat = 0;
		if (isset($this->srcImpData['VATApplicable']) && strtolower($this->srcImpData['VATApplicable']) === 'true')
			$vat = 1;

		$this->docHead['taxType'] = 0; 		// tuzemsko
		$this->docHead['taxMethod'] = 1; 	// z hlavičky
		$this->docHead['taxCalc'] = 0;		// nedaňový doklad

		if ($vat)
		{
			$this->docHead['taxCalc'] = 1;
		}

		if (isset($this->srcImpData['ID']))
			$this->docHead['docId'] = $this->valueStr($this->srcImpData['ID'], 40);
		if (isset($this->srcImpData['Note']))
			$this->docHead['title'] = $this->valueStr($this->srcImpData['Note'], 120);


		if (isset($this->srcImpData['IssueDate']))
			$this->docHead['dateIssue'] = $this->date($this->srcImpData['IssueDate']);
		if (isset($this->srcImpData['TaxPointDate']))
		{
			$this->docHead['dateAccounting'] = $this->date($this->srcImpData['TaxPointDate']);
			$this->docHead['dateTax'] = $this->date($this->srcImpData['TaxPointDate']);
			$this->docHead['dateTaxDuty'] = $this->date($this->srcImpData['TaxPointDate']);

			$this->loadSourceInfo();

			if ($this->issueRecData)
			{
				$this->docHead['dateTax'] = Utils::createDateTime($this->issueRecData['dateIncoming']);
			}
		}

		$vatRegs = $this->app()->cfgItem('e10doc.base.taxRegs', NULL);
		if ($vatRegs)
			$this->docHead['vatReg'] = key($vatRegs);

		$this->tableDocsHeads->findAndSetFiscalPeriod ($this->docHead);

		if (isset($this->srcImpData['ForeignCurrencyCode']))
			$this->docHead['currency'] = strtolower($this->valueStr($this->srcImpData['ForeignCurrencyCode'], 3));
		else
			$this->docHead['currency'] = $this->docHead['homeCurrency'];

		if (isset($this->srcImpData['CurrRate']))
			$this->docHead['exchangeRate'] = $this->valueNumber($this->srcImpData['CurrRate']);
		else
			$this->docHead['exchangeRate'] = 1;

		$this->importPayment();
	}

	protected function importRows()
	{
		if (!isset($this->srcImpData['InvoiceLines']))
			return;

		$invLines = (isset($this->srcImpData['InvoiceLines']['InvoiceLine'][0])) ? $this->srcImpData['InvoiceLines']['InvoiceLine'] : $this->srcImpData['InvoiceLines'];
		foreach ($invLines as $il)
		{
			$this->importRow($il);
		}

		if (isset($this->srcImpData['NonTaxedDeposits']))
		{
			$nonTaxedDeposits = (isset($this->srcImpData['NonTaxedDeposits']['NonTaxedDeposit'][0])) ? $this->srcImpData['NonTaxedDeposits']['NonTaxedDeposit'] : $this->srcImpData['NonTaxedDeposits'];
			foreach ($nonTaxedDeposits as $dep)
			{
				$row = ['operation' => 1020101, 'taxCode' => 'EUCZ000', 'taxPercents' => 0, 'quantity' => 1];
				$row['priceItem'] = $this->valueNumber($dep['DepositAmount']);
				if ($row['priceItem'] > 0)
					$row['priceItem'] = - $row['priceItem'];
				$row['taxCalc'] = 0;
				$row['text'] = 'Odpočet nedaňové zálohy';
				if (isset($dep['VariableSymbol']))
					$row['symbol1'] = $this->valueStr($dep['VariableSymbol'], 20);
				$this->docRows[] = $row;
			}
		}

		if (isset($this->srcImpData['TaxedDeposits']))
		{
			$taxedDeposits = (isset($this->srcImpData['TaxedDeposits']['TaxedDeposit'][0])) ? $this->srcImpData['TaxedDeposits']['TaxedDeposit'] : $this->srcImpData['TaxedDeposits'];
			foreach ($taxedDeposits as $dep)
			{
				$row = ['operation' => 1020101, 'quantity' => 1];
				$row['priceItem'] = $this->valueNumber($dep['TaxInclusiveDepositAmount']);
				if ($row['priceItem'] > 0)
					$row['priceItem'] = - $row['priceItem'];
				$row['taxCalc'] = 1;
				$row['text'] = 'Odpočet daňové zálohy';
				if (isset($dep['VariableSymbol']))
					$row['symbol1'] = $this->valueStr($dep['VariableSymbol'], 20);

				if (isset($dep['ClassifiedTaxCategory']['Percent']))
					$this->checkVat(floatval($dep['ClassifiedTaxCategory']['Percent']), $row);

				$this->docRows[] = $row;
			}
		}
	}

	protected function importPayment()
	{
		if (!isset($this->srcImpData['PaymentMeans']))
			return;

		$paymentsLines = (isset($this->srcImpData['PaymentMeans']['Payment'][0])) ? $this->srcImpData['PaymentMeans']['Payment'] : $this->srcImpData['PaymentMeans'];
		foreach ($paymentsLines as $pl)
		{
			if (isset($pl['Details']['PaymentDueDate']))
				$this->docHead['dateDue'] = $this->date($pl['Details']['PaymentDueDate']);
			if (isset($pl['Details']['VariableSymbol']))
				$this->docHead['symbol1'] = $this->valueStr($pl['Details']['VariableSymbol'], 20);
			if (isset($pl['Details']['SpecificSymbol']))
				$this->docHead['symbol2'] = $this->valueStr($pl['Details']['SpecificSymbol'], 20);
			//if (isset($pl['Details']['ConstantSymbol']))
			//	$this->docHead['symbol3'] = $this->valueStr($pl['Details']['ConstantSymbol'], 20);

			if (isset($pl['Details']['ID']) && isset($pl['Details']['BankCode']))
				$this->docHead['bankAccount'] = $this->valueStr($pl['Details']['ID'].'/'.$pl['Details']['BankCode'], 20);

			if (isset($pl['PaymentMeansCode']))
			{
				$pc = strval($pl['PaymentMeansCode']);
				if (isset(self::$isDocPaymentMethods[$pc]))
					$this->docHead['paymentMethod'] = self::$isDocPaymentMethods[$pc];
			}

			break;
		}
	}

	protected function importRow($il)
	{
		$negativePrices = intval($this->srcImpData['DocumentType']) === 2; // dobropis
		$testDocRowPriceSource = intval($this->app()->cfgItem('options.experimental.testDocRowPriceSource', 0));

		$row = [];

		$fc = ($this->docHead['currency'] != $this->docHead['homeCurrency']);

		if (isset($il['Item']['Description']))
			$row['text'] = $this->valueStr($il['Item']['Description'], 220);

		if (isset($il['InvoicedQuantity']))
			$row['quantity'] = $this->valueNumber($il['InvoicedQuantity']);
		else
			$row['quantity'] = 1.0;

		$vatPayer = $this->tableDocsHeads->cfgItemTaxPayer ($this->docHead);
		$priceWithVat = (isset($il['ClassifiedTaxCategory']['VATCalculationMethod'])) ? intval($il['ClassifiedTaxCategory']['VATCalculationMethod']) : 0;

		if ($vatPayer)
		{
			if ($priceWithVat)
			{
				$row['priceItem'] = $this->valueNumber($fc ? $il['UnitPriceTaxInclusiveCurr'] : $il['UnitPriceTaxInclusive']);
				$row['taxCalc'] = 2;

				if ($testDocRowPriceSource)
				{
					$row['priceAll'] = $this->valueNumber($fc ? $il['LineExtensionAmountTaxInclusiveCurr'] : $il['LineExtensionAmountTaxInclusive']);
					$row['priceSource'] = 1;
				}
				else
					$row['priceAll'] = $row['quantity'] * $row['priceItem'];//$this->valueNumber($il['LineExtensionAmount']);
			}
			else
			{
				$row['priceItem'] = $this->valueNumber($il['UnitPrice']);
				$row['taxCalc'] = 1;

				if ($testDocRowPriceSource)
				{
					$row['priceAll'] = $this->valueNumber($fc ? $il['LineExtensionAmountCurr'] : $il['LineExtensionAmount']);
					$row['priceSource'] = 1;
				}
				else
					$row['priceAll'] = $row['quantity'] * $row['priceItem'];//$this->valueNumber($il['LineExtensionAmount']);
			}
		}
		else
		{
			$row['priceItem'] = $fc ? $this->valueNumber($il['UnitPriceTaxInclusiveCurr']) : $this->valueNumber($il['UnitPriceTaxInclusive']);

			if ($testDocRowPriceSource)
			{
				$row['priceAll'] = $fc ? $this->valueNumber($il['LineExtensionAmountTaxInclusiveCurr']) : $this->valueNumber($il['LineExtensionAmountTaxInclusive']);
				$row['priceSource'] = 1;
			}
			else
				$row['priceAll'] = $row['quantity'] * $row['priceItem'];
		}

		if ($negativePrices && $row['priceItem'] > 0.0)
			$row['priceItem'] = - $row['priceItem'];

		if (isset($il['ClassifiedTaxCategory']['Percent']))
			$this->checkVat(floatval($il['ClassifiedTaxCategory']['Percent']),$row);
		//else
		//	$this->checkVat(0.0, $row);

		$itemInfo = [
			'supplierCode' => '',
			'manufacturerCode' => '',
			'itemFullName' => '',
			'itemShortName' => '',
		];

		if (isset($il['Item']['SellersItemIdentification']['ID']) && $this->valueStr($il['Item']['SellersItemIdentification']['ID'], 60) !== '')
			$itemInfo['supplierCode'] = $this->valueStr($il['Item']['SellersItemIdentification']['ID'], 60);

		if (isset($il['Item']['CatalogueItemIdentification']['ID']) && $this->valueStr($il['Item']['CatalogueItemIdentification']['ID'], 60) !== '')
			$itemInfo['manufacturerCode'] = $this->valueStr($il['Item']['CatalogueItemIdentification']['ID'], 60);

		if (isset($il['Item']['Description']) && $il['Item']['Description'] !== '')
			$itemInfo['itemFullName'] = $il['Item']['Description'];

		$this->searchItem($itemInfo, $il, $row);
		$this->checkItem($il, $row);

		$row['!itemInfo'] = $itemInfo;

		$this->applyRowSettings($row);
		$this->applyDocsImportSettings($row);

		if (count($row))
			$this->docRows[] = $row;
	}

	function checkPersons()
	{
		if (isset($this->srcImpData['AccountingSupplierParty']['Party']['PartyName']['Name']))
			$this->importProtocol['person']['src']['fullName'] = $this->srcImpData['AccountingSupplierParty']['Party']['PartyName']['Name'];
		if (isset($this->srcImpData['AccountingSupplierParty']['Party']['PartyIdentification']['ID']))
		{
			$oid = $this->srcImpData['AccountingSupplierParty']['Party']['PartyIdentification']['ID'];

			$this->importProtocol['person']['src']['oid'] = $oid;
			if (isset($this->srcImpData['AccountingSupplierParty']['Party']['PartyTaxScheme']['CompanyID']))
				$this->importProtocol['person']['src']['vatId'] = $this->srcImpData['AccountingSupplierParty']['Party']['PartyTaxScheme']['CompanyID'];

			$personNdx = $this->searchPerson('ids', 'oid', $oid);
			if ($personNdx)
			{
				$this->docHead['person'] = $personNdx;

				if ($personNdx)
					$this->setInboxPersonFrom($personNdx);

				return;
			}

			// -- create new person
			$checkIncomingIssues = intval($this->app()->cfgItem('options.experimental.checkIncomingIssues', 0));

			if ($checkIncomingIssues)
			{
				$reg = new \e10\persons\libs\register\PersonRegister($this->app());
				$reg->addDocState = 4000;
				$reg->addDocStateMain = 2;
				$reg->addPerson($oid);
				$personNdx = $reg->personNdx;
				$this->docHead['person'] = $personNdx;

				if ($personNdx)
					$this->setInboxPersonFrom($personNdx);

				return;
			}
		}
	}

	function createSrcSimplifiedData()
	{
		$simpleXml = simplexml_load_string($this->ddfRecData['srcData'] ?? $this->fileContent);
		$json = json_decode (json_encode($simpleXml), TRUE);

		$json['ISDocVersion'] = $json['@attributes']['version'];
		unset ($json['@attributes']);
		unset ($json['comment']);

		return $json;
	}

}

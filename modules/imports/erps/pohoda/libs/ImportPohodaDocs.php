<?php

namespace imports\erps\pohoda\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Str, \e10\json;
use \Shipard\Utils\World;


/**
 * class ImportPohoda
 */
class ImportPohodaDocs extends \imports\erps\pohoda\libs\ImportPohoda
{
  var $docs = [];
	var $xml;

	public function importFiles ($fileNames)
	{
		$files = explode(',', $fileNames);
		foreach ($files as $fileName)
		{
			$this->openFile($fileName);
			$this->importDocs();
		}

		$this->saveAllDocs();
	}

	public function openFile ($fileName)
	{
		$xmlTxt = file_get_contents($fileName);
		$this->xml = simplexml_load_string ($xmlTxt);
	}

  public function importDocs ()
	{
		$ns = $this->xml->getDocNamespaces();
		if ($this->xml->children($ns['rsp'])->responsePackItem->children($ns['lst'])->listInvoice)
		{
			$this->importDocsInvoices();
		}

		if ($this->xml->children($ns['rsp'])->responsePackItem->children($ns['lst'])->listVoucher)
		{
			$this->importDocsCash();
		}
	}

  public function importDocsInvoices ()
	{
		$ns = $this->xml->getDocNamespaces();
		foreach ($this->xml->children($ns['rsp'])->responsePackItem->children($ns['lst'])->listInvoice->invoice as $invoice)
		{
			$header = $invoice->children($ns['inv'])->invoiceHeader;
			$partner = $header->partnerIdentity->children($ns['typ']);

			$summary = $invoice->children($ns['inv'])->invoiceSummary;
			$homeCurrency = $summary->homeCurrency->children($ns['typ']);

			$taxCalc = 0; // none
			$classificationVAT = $header->classificationVAT->children($ns['typ']);
			if ($classificationVAT)
			{
				$taxCalc = 1;
				if (strval($classificationVAT->classificationVATType ?? '') === 'nonSubsume')
					$taxCalc = 0;
			}

			//if (strval($summary->typeCalculateVATInclusivePrice ?? '') === 'VATNewMethod' && $taxCalc != 0)
			//	$taxCalc = 3;

			$foreignCurrencyId = '';
			$foreignCurrencyRate = 0.0;
			$foreignCurrency = $summary->foreignCurrency->children($ns['typ']);
			if ($foreignCurrency)
			{
				$foreignCurrencyId = strtolower(strval($foreignCurrency->currency->ids));
				$foreignCurrencyRate = floatval($foreignCurrency->rate);
			}

			$docType = 'invni';
			$impDoc = new \imports\erps\core\libs\ImportedDoc($this->app());
			$impDoc->init($docType);

			$docImportId = strval($header->id ?? '');

			$linkId = 'PX-'.$docType.'-'.$docImportId;
			$existedDoc = $this->db()->query('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', $docType, ' AND [linkId] = %s', $linkId)->fetch();
			if ($existedDoc)
			{
				$impDoc->setReplaceDocumentNdx($existedDoc['ndx']);
			}

			$dbCounter = 2;
			$personNdx = $this->checkPerson($partner);

			$newDocHead = [
					'linkId' => $linkId,
					'dbCounter' => $dbCounter,
					'person' => $personNdx,
					'taxCalc' => $taxCalc,
					'symbol1' => strval($header->symVar),
					'dateIssue' => strval($header->date),
					'dateTax' => strval($header->dateTax),
					'dateAccounting' => strval($header->dateAccounting),
					'dateDue' => strval($header->dateDue),
					'title' => Str::upToLen(strval($header->text), 120),
			];

			if ($foreignCurrencyId !== '')
			{
				$newDocHead['currency'] = $foreignCurrencyId;
				$newDocHead['exchangeRate'] = $foreignCurrencyRate;
				$newDocHead['dateExchRate'] = $newDocHead['dateIssue'];
			}

			if (strval($header->originalDocument ?? '') !== '')
				$newDocHead['docId'] = strval($header->originalDocument);

			$paymentType = $header->paymentType->children($ns['typ']);
			if ($paymentType)
			{
				if ($paymentType->paymentType == 'cash')
					$newDocHead['paymentMethod'] = 5;
				elseif ($paymentType->paymentType == 'creditcard')
					$newDocHead['paymentMethod'] = 2;
			}

			$bankAccount = $header->paymentAccount->children($ns['typ']);
			if ($bankAccount)
			{
				$bankAccountNumber = '';
				if (strval($bankAccount->accountNo ?? '') !== '')
					$bankAccountNumber = strval($bankAccount->accountNo);
				if (strval($bankAccount->bankCode ?? '') !== '')
					$bankAccountNumber .= '/'.strval($bankAccount->bankCode);
				$newDocHead['bankAccount'] = $bankAccountNumber;
			}

			$impDoc->createHead($newDocHead);

			$detail = $invoice->children($ns['inv'])->invoiceDetail;
			forEach ($detail->invoiceItem as $row)
			{
				$rowHomeCurrency = $row->homeCurrency->children($ns['typ']);
				$rowForeignCurrency = $row->foreignCurrency->children($ns['typ']);

				$vatPercentRate = -1;
				$vatAttrs = $row->rateVAT->attributes();
				if ($vatAttrs && isset($vatAttrs['value']))
					$vatPercentRate = floatval($vatAttrs['value']);

				$vatPercentRow = floatval($row->percentVAT ?? -1);

				$rateVAT = strval($row->rateVAT ?? '');
				$newDocRow = [
					'text' => Str::upToLen(strval($row->text ?? ''), 220),
					'quantity' => doubleval ($row->quantity ?? 1),
					'priceSource' => 1,
				];

				/*
				$row['priceAll'] = $r['priceAll'];
				$row['priceSource'] = 1;
				*/

				if ($foreignCurrencyId !== '' && $rowForeignCurrency)
				{
					$newDocRow['priceAll'] = doubleval ($rowForeignCurrency->price);
					//$newDocRow['priceItem'] = doubleval ($rowForeignCurrency->unitPrice);
				}
				else
				{
					$newDocRow['priceAll'] = doubleval ($rowHomeCurrency->price);
					//$newDocRow['priceItem'] = doubleval ($rowHomeCurrency->unitPrice);
				}

				if ($vatPercentRow != -1)
					$this->checkVat($vatPercentRow, $newDocHead, $newDocRow);
				elseif ($vatPercentRate != -1)
					$this->checkVat($vatPercentRate, $newDocHead, $newDocRow);

				$impDoc->docRows[] = $newDocRow;
			}

			$docToImport = [
				'order' => $newDocHead['dateIssue'].'_1',
				'impDoc' => $impDoc,
			];
			$this->docs[] = $docToImport;
		}
	}

  public function importDocsCash ()
	{
		$ns = $this->xml->getDocNamespaces();
		foreach ($this->xml->children($ns['rsp'])->responsePackItem->children($ns['lst'])->listVoucher->voucher as $invoice)
		{
			$header = $invoice->children($ns['vch'])->voucherHeader;
			$partner = $header->partnerIdentity->children($ns['typ']);

			$summary = $invoice->children($ns['vch'])->voucherSummary;
			$homeCurrency = $summary->homeCurrency->children($ns['typ']);

			$taxCalc = 0; // none
			$classificationVAT = $header->classificationVAT->children($ns['typ']);
			if ($classificationVAT)
			{
				$taxCalc = 1;
				if (strval($classificationVAT->classificationVATType ?? '') === 'nonSubsume')
					$taxCalc = 0;
			}

			//if (strval($summary->typeCalculateVATInclusivePrice ?? '') === 'VATNewMethod' && $taxCalc != 0)
			//	$taxCalc = 3;

			$foreignCurrencyId = '';
			$foreignCurrencyRate = 0.0;
			$foreignCurrency = $summary->foreignCurrency->children($ns['typ']);
			if ($foreignCurrency)
			{
				$foreignCurrencyId = strtolower(strval($foreignCurrency->currency->ids));
				$foreignCurrencyRate = floatval($foreignCurrency->rate);
			}

			$docType = 'cash';
			$impDoc = new \imports\erps\core\libs\ImportedDoc($this->app());
			$impDoc->init($docType);

			$docImportId = strval($header->id ?? '');

			$linkId = 'PX-'.$docType.'-'.$docImportId;
			$existedDoc = $this->db()->query('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', $docType, ' AND [linkId] = %s', $linkId)->fetch();
			if ($existedDoc)
			{
				$impDoc->setReplaceDocumentNdx($existedDoc['ndx']);
			}

			$dbCounter = 2;
			$cashBox = 1;

			$cashBoxDir = 1; // 1 = in, 2 = out
			if (strval($header->voucherType ?? '') === 'expense')
				$cashBoxDir = 2;

			$personNdx = $this->checkPerson($partner);

			$newDocHead = [
					'linkId' => $linkId,
					'cashBox' => $cashBox,
					'cashBoxDir' => $cashBoxDir,
					'person' => $personNdx,
					'taxCalc' => $taxCalc,
					'symbol1' => strval($header->symVar),
					'dateIssue' => strval($header->date),
					'dateTax' => strval($header->dateTax),
					'dateAccounting' => strval($header->datePayment),
					'dateDue' => strval($header->datePayment),
					'title' => Str::upToLen(strval($header->text), 120),
			];

			if ($foreignCurrencyId !== '')
			{
				$newDocHead['currency'] = $foreignCurrencyId;
				$newDocHead['exchangeRate'] = $foreignCurrencyRate;
				$newDocHead['dateExchRate'] = $newDocHead['dateIssue'];
			}

			if (strval($header->originalDocument ?? '') !== '')
				$newDocHead['docId'] = strval($header->originalDocument);

			$paymentType = $header->paymentType->children($ns['typ']);
			if ($paymentType)
			{
				if ($paymentType->paymentType == 'cash')
					$newDocHead['paymentMethod'] = 5;
				elseif ($paymentType->paymentType == 'creditcard')
					$newDocHead['paymentMethod'] = 2;
			}

			$bankAccount = $header->paymentAccount->children($ns['typ']);
			if ($bankAccount)
			{
				$bankAccountNumber = '';
				if (strval($bankAccount->accountNo ?? '') !== '')
					$bankAccountNumber = strval($bankAccount->accountNo);
				if (strval($bankAccount->bankCode ?? '') !== '')
					$bankAccountNumber .= '/'.strval($bankAccount->bankCode);
				$newDocHead['bankAccount'] = $bankAccountNumber;
			}

			$impDoc->createHead($newDocHead);

			$detail = $invoice->children($ns['vch'])->voucherDetail;
			forEach ($detail->voucherItem as $row)
			{
				$rowHomeCurrency = $row->homeCurrency->children($ns['typ']);
				$rowForeignCurrency = $row->foreignCurrency->children($ns['typ']);

				$vatPercentRate = -1;
				$vatAttrs = $row->rateVAT->attributes();
				if ($vatAttrs && isset($vatAttrs['value']))
					$vatPercentRate = floatval($vatAttrs['value']);

				$vatPercentRow = floatval($row->percentVAT ?? -1);

				$rateVAT = strval($row->rateVAT ?? '');
				$newDocRow = [
					'text' => Str::upToLen(strval($row->text ?? ''), 220),
					'quantity' => doubleval ($row->quantity ?? 1),
					'priceSource' => 1,
				];

				if ($foreignCurrencyId !== '' && $rowForeignCurrency)
				{
					$newDocRow['priceAll'] = doubleval ($rowForeignCurrency->price);
					//$newDocRow['priceItem'] = doubleval ($rowForeignCurrency->unitPrice);
				}
				else
				{
					$newDocRow['priceAll'] = doubleval ($rowHomeCurrency->price);
					//$newDocRow['priceItem'] = doubleval ($rowHomeCurrency->unitPrice);
				}

				if ($vatPercentRow != -1)
					$this->checkVat($vatPercentRow, $newDocHead, $newDocRow);
				elseif ($vatPercentRate != -1)
					$this->checkVat($vatPercentRate, $newDocHead, $newDocRow);

				$symbol1 = strval($row->symPar ?? '');
				if ($symbol1 !== '')
				{
					$newDocRow['symbol1'] = $symbol1;
					$newDocRow['operation'] = 1030002;
				}

				$impDoc->docRows[] = $newDocRow;
			}

			$docToImport = [
				'order' => $newDocHead['dateIssue'].'_1',
				'impDoc' => $impDoc,
			];
			$this->docs[] = $docToImport;

			echo json_encode($impDoc->docHead, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).";\n";
		}
	}

	public function saveAllDocs()
	{
		$docs = \e10\sortByOneKey($this->docs, 'order', FALSE, TRUE);
		foreach ($docs as $doc)
		{
			echo '# '.$doc['order']."\n";
			$impDoc = $doc['impDoc'];
			$impDoc->saveDoc();
		}
	}

	protected function checkPerson ($partner)
	{
		$importPartnerId = strval($partner->id ?? '');
		if ($importPartnerId !== '')
		{
			$importPartnerId = 'PX'.$importPartnerId;
			$personRecData = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE [id] = %s', $importPartnerId)->fetch();
			if ($personRecData)
				return $personRecData['ndx'];
		}

		$oid = strval($partner->address->ico ?? '');
		if ($oid !== '')
		{
			$q = [];
			array_push ($q, 'SELECT * FROM [e10_base_properties] AS props');
			array_push ($q, ' WHERE [valueString] = %s', $oid);
			array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'oid');

			$rows = $this->db()->query ($q);
			foreach ($rows as $r)
			{
				$personNdx = $r['recid'];
				if ($importPartnerId !== '')
				{
					$this->db()->query ('UPDATE [e10_persons_persons] SET [id] = %s', $importPartnerId, ' WHERE [ndx] = %i', $personNdx);
				}
				return $personNdx;
			}
		}

		$newPerson = [];
		$newPerson ['person'] = [];

		if (isset($partner->address->company))
		{
			$newPerson ['person']['company'] = 1;
			$newPerson ['person']['fullName'] = strval($partner->address->company);
		}
		elseif (isset($partner->address->name))
		{
			$newPerson ['person']['company'] = 1;
			$newPerson ['person']['fullName'] = strval($partner->address->name);
		}
		else//if (isset($partner->address->company))
		{
			$newPerson ['person']['company'] = 0;
			//$newPerson ['person']['firstName'] = $this->recData['firstName'.$sfx];
			//$newPerson ['person']['lastName'] = $this->recData['lastName'.$sfx];
			//$newPerson ['person']['fullName'] = $this->recData['lastName'.$sfx].' '.$this->recData['firstName'.$sfx];
		}
		$newPerson ['person']['docState'] = 4000;
		$newPerson ['person']['docStateMain'] = 2;

		$newAddress = [];
		if ($importPartnerId !== '')
			$newPerson['person']['id'] = $importPartnerId;
		$newAddress ['street'] = strval($partner->address->street);
		$newAddress ['city'] = strval($partner->address->city);
		$newAddress ['zipcode'] = str_replace(' ', '', strval($partner->address->zip));
		$newAddress ['worldCountry'] = World::countryNdx($this->app(), $this->app()->cfgItem ('options.core.ownerDomicile', 'cz'));
		$newAddress ['country'] = World::countryId($this->app(), $newAddress ['worldCountry']);//$this->app()->cfgItem ('options.core.ownerDomicile', 'cz');

		if (($partner->address->ico ?? '') !== '')
			$newPerson ['ids'][] = ['type' => 'oid', 'value' => strval($partner->address->ico)];
		if (($partner->address->dic ?? '') !== '')
		{
			$vatId = strval($partner->address->dic);
			$newPerson ['ids'][] = ['type' => 'taxid', 'value' => $vatId];

			if (substr($vatId, 0, 2) !== 'CZ')
			{
				$countryCode = strtolower(substr($vatId, 0, 2));
				$newAddress ['worldCountry'] = World::countryNdx($this->app(), $countryCode);
				$newAddress ['country'] = World::countryId($this->app(), $newAddress ['worldCountry']);//$this->app()->cfgItem ('options.core.ownerDomicile', 'cz');
			}
		}

		$newPerson ['address'][] = $newAddress;

		if (($partner->address->mobilPhone ?? '') !== '')
			$newPerson ['contacts'][] = ['type' => 'phone', 'value' => strval($partner->address->mobilPhone)];

		//echo json_encode($newPerson, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).";";
		$newPersonNdx = \E10\Persons\createNewPerson ($this->app, $newPerson);
		//$tablePersons->docsLog ($this->newPersonNdx);


		//echo "-`$partnerId`-"."--".json_encode($partner->address, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).";";
		return $newPersonNdx;
	}

  public function run()
  {
    echo "__IMPORT POHODA__\n";
  }
}


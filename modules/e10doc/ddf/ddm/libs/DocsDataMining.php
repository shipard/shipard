<?php

namespace e10doc\ddf\ddm\libs;
use \e10\json, \Shipard\Utils\Str;
use \e10doc\core\libs\E10Utils;


/**
 * class DocsDataMining
 */
class DocsDataMining extends \e10doc\ddf\core\libs\Core
{
	public function createImport()
	{
		if (isset($this->ddfRecData['srcData']))
			$this->fileContent = $this->ddfRecData['srcData'];

		if ($this->checkOldWay())
			return;

		$this->checkLocalDDMs();
	}

	protected function checkLocalDDMs()
	{
		$q = [];
		array_push ($q, 'SELECT * FROM [e10doc_ddm_ddm]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] IN %in', [4000, 8000, 1000]);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!Str::strstr($this->fileContent, $r['signatureString']))
				continue;

			$formatDef = Json::decode($r['configuration']);
			$ddmEngine = new \e10doc\ddm\libs\DDMEngine($this->app());
			$ddmEngine->importDataText($this->fileContent, $formatDef);

			$this->srcImpData = ['head' => $ddmEngine->docHeadSrcItems];
			$this->importHead();
			$this->importRows();
			$this->addRowsFromSettings();

			$this->impData = ['head' => $this->docHead, 'rows' => $this->docRows];

			//echo json_encode($ddmEngine->docHeadSrcItems)."\n";

			return;
		}
	}

	protected function checkOldWay()
	{
		$allFormats = $this->app()->cfgItem('e10.ddf.ddm.formats');

		foreach ($allFormats as $oneFormatId => $oneFormat)
		{
			//echo $oneFormatId.": ";

			$formatDef = json_decode(file_get_contents(__SHPD_MODULES_DIR__.'e10doc/ddf/ddm/config/formats/'.$oneFormatId.'.json'), TRUE);

			// -- test format
			$matches = [];
			preg_match('/'.$formatDef['signatureRegExp'].'/', $this->fileContent/*$this->ddfRecData['srcData']*/, $matches);
			if (!count($matches))
			{
				//echo " --- signatureRegExp not found\n";
				continue;
			}

			$ddmEngine = new \e10doc\ddm\libs\Engine($this->app());
			//$ddmEngine->setSrcText($this->formatRecData['testText']);
			$ddmEngine->importDataText($this->fileContent/*$this->ddfRecData['srcData']*/, $formatDef);

			//echo json_encode($ddmEngine->docHeadSrcItems)."\n";

			$this->srcImpData = ['head' => $ddmEngine->docHeadSrcItems];
			if (isset($formatDef['formatEngine']))
			{
				/** @var \e10doc\ddf\ddm\formatsEngines\CoreFE */
				$fe = $this->app()->createObject($formatDef['formatEngine']);
				if ($fe)
				{
					$fe->setSrcText($this->fileContent);
					$fe->import($this->srcImpData);
					$this->addRowsFromSettings();

					$this->srcImpData['rows'] = $fe->docRows;
					//echo "Format ENGINE2!\n";
				}
			}

			$this->importHead();
			$this->importRows();

			$this->impData = ['head' => $this->docHead, 'rows' => $this->docRows];
			return TRUE;
		}

		return FALSE;
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

		$ci = [
			'name' => 'PDF',
			'icon' => 'system/iconFilePdf',
			'content' => $this->previewAtt(),
		];
		$c[] = $ci;

		// -- src data
		$ci = [
			'name' => 'Originál',
			'icon' => 'user/fileText',
			'content' => ['type' => 'text', 'subtype' => 'code', 'text' => $this->ddfRecData['srcData']]
		];
		$c[] = $ci;

		// -- simplifiedData
		$ci = [
			'name' => 'Vytěženo',
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

	protected function applyCoreHeadValues($values)
	{
	}

	public function checkFileContent()
	{
		$this->createImport();

		if (!$this->srcImpData)
			return;

		$this->ddfId = 1001;
		$this->addFirstContent();
		$this->updateInbox();
	}

	protected function importHead()
	{
		$this->docHead['docType'] = 'invni';
		$this->docHead['docState'] = 1000;
		$this->docHead['docStateMain'] = 0;

		$this->checkPersons();
		$this->loadPerson();

		$vat = 0;
		if (isset($this->srcImpData['head']['vat']) && $this->srcImpData['head']['vat'])
			$vat = 1;

		$this->docHead['taxType'] = 0; 		// tuzemsko
		$this->docHead['taxMethod'] = 1; 	// z hlavičky
		$this->docHead['taxCalc'] = 0;		// nedaňový doklad

		if ($vat)
		{
			$this->docHead['taxCalc'] = 1;
		}

		if (isset($this->srcImpData['head']['documentId']))
			$this->docHead['docId'] = $this->srcImpData['head']['documentId']; //$this->valueStr($this->srcImpData['ID'], 40);
		//if (isset($this->srcImpData['Note']))
		//	$this->docHead['title'] = $this->valueStr($this->srcImpData['Note'], 120);

		if (isset($this->srcImpData['head']['taxType']))
			$this->docHead['taxType'] = $this->srcImpData['head']['taxType'];

		if (isset($this->srcImpData['head']['dateIssue']))
			$this->docHead['dateIssue'] = $this->srcImpData['head']['dateIssue'];
		if (isset($this->srcImpData['head']['dateTax']))
			$this->docHead['dateTax'] = $this->srcImpData['head']['dateTax'];
		if (isset($this->srcImpData['head']['dateDue']))
			$this->docHead['dateDue'] = $this->srcImpData['head']['dateDue'];

		if (isset($this->srcImpData['head']['symbol1']))
			$this->docHead['symbol1'] = $this->srcImpData['head']['symbol1'];
		if (isset($this->srcImpData['head']['bankAccount']))
			$this->docHead['bankAccount'] = $this->srcImpData['head']['bankAccount'];

		//echo json_encode($this->docHead);
	}

	protected function importRows()
	{
		if (!isset($this->srcImpData['rows']))
			return;

		foreach ($this->srcImpData['rows'] as $r)
		{
			$this->importRow($r);
		}
	}

	protected function importRow($r)
	{
		//$negativePrices = 0;
		$testDocRowPriceSource = intval($this->app()->cfgItem('options.experimental.testDocRowPriceSource', 0));

		$row = [];

		if (isset($r['itemFullName']))
			$row['text'] = $this->valueStr($r['itemFullName'], 220);
		elseif (isset($r['itemShortName']))
			$row['text'] = $this->valueStr($r['itemShortName'], 220);

		if (isset($r['quantity']))
			$row['quantity'] = $r['quantity'];
		else
			$row['quantity'] = 1.0;

		if (isset($r['unit']))
			$row['unit'] = $r['unit'];

		$priceWithVat = 0;//(isset($il['ClassifiedTaxCategory']['VATCalculationMethod'])) ? intval($il['ClassifiedTaxCategory']['VATCalculationMethod']) : 0;
		if ($priceWithVat)
		{
			if (isset($r['priceItem']))
			{
				$row['priceItem'] = $r['priceItem'];
				$row['taxCalc'] = 2;
			}

			if ($testDocRowPriceSource)
			{
				$row['priceAll'] = $r['priceAll'];
				$row['priceSource'] = 1;
			}
		}
		else
		{
			if (isset($r['priceItem']))
			{
				$row['priceItem'] = $r['priceItem'];
				$row['taxCalc'] = 2;
			}

			if ($testDocRowPriceSource && isset($r['priceAll']))
			{
				$row['priceAll'] = $r['priceAll'];
				$row['priceSource'] = 1;
			}
		}

		if (isset($r['vatPercent']))
			$this->checkVat($r['vatPercent'], $row);
		//else
		//	$this->checkVat(0.0, $row);


		$itemInfo = [
			'supplierCode' => '',
			'manufacturerCode' => '',
			'itemFullName' => '',
			'itemShortName' => '',
		];

		if (isset($r['itemProperties']) && isset($r['itemProperties']['supplierItemCode']) && $r['itemProperties']['supplierItemCode'] !== '')
			$itemInfo['supplierCode'] = $r['itemProperties']['supplierItemCode'];

		if (isset($r['itemProperties']) && isset($r['itemProperties']['manufacturerItemCode']) && $r['itemProperties']['manufacturerItemCode'] !== '')
			$itemInfo['manufacturerCode'] = $r['itemProperties']['manufacturerItemCode'];

		if (isset($r['itemProperties']) && isset($r['itemProperties']['supplierItemUrl']) && $r['itemProperties']['supplierItemUrl'] !== '')
			$itemInfo['supplierItemUrl'] = $r['itemProperties']['supplierItemUrl'];

		if (isset($r['itemFullName']) && $r['itemFullName'] !== '')
			$itemInfo['itemFullName'] = $r['itemFullName'];
		if (isset($r['itemShortName']) && $r['itemShortName'] !== '')
			$itemInfo['itemShortName'] = $r['itemShortName'];

		$row['!itemInfo'] = $itemInfo;

		$this->searchItem($itemInfo, $r, $row);
		$this->checkItem($r, $row);

		$this->applyRowSettings($row);
		$this->applyDocsImportSettings($row);

		if (count($row))
			$this->docRows[] = $row;
	}

	function checkPersons()
	{
		// $this->srcImpData['rows']
		if (isset($this->srcImpData['head']['person']['natId']))
		{
			$oid = $this->srcImpData['head']['person']['natId'];
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

		if (isset($this->srcImpData['head']['person']['vatId']))
		{
			$vatId = $this->srcImpData['head']['person']['vatId'];
			$this->importProtocol['person']['src']['vatId'] = $vatId;
			$personNdx = $this->searchPerson('ids', 'taxid', $vatId);
			if ($personNdx)
			{
				$this->docHead['person'] = $personNdx;

				if ($personNdx)
					$this->setInboxPersonFrom($personNdx);

				return;
			}
		}
	}

	protected function valueStr($value, $maxLen)
	{
		if (is_string($value))
			return Str::upToLen($value, $maxLen);

		return Str::upToLen(strval($value), $maxLen);
	}
}

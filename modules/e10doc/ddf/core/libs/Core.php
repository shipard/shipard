<?php

namespace e10doc\ddf\core\libs;
use \e10\json, \e10\utils, \Shipard\Utils\Str;
use \e10doc\core\libs\E10Utils;
use \Shipard\Utils\World;


/**
 * Class Core
 * @package e10doc\ddf\core\libs
 */
class Core extends \lib\docDataFiles\DocDataFile
{
	var $docHead = [];
	var $docRows = [];
	var $replaceDocumentNdx = 0;
	var $personRecData = NULL;

	protected function date($date)
	{
		$d = utils::createDateTime($date);
		if ($d)
			return $d->format('Y-m-d');

		return NULL;
	}

	function valueNumber($v)
	{
		$number = floatval($v);

		return $number;
	}

	protected function valueStr($value, $maxLen)
	{
		if (is_string($value))
			return Str::upToLen($value, $maxLen);

		return '';
	}

	function searchPerson($group, $id, $value)
	{
		$q[] = 'SELECT props.recid';

		array_push ($q,	' FROM [e10_base_properties] AS props');
		array_push ($q,	' LEFT JOIN [e10_persons_persons] AS persons ON props.recid = persons.ndx');
		array_push ($q,	' WHERE 1');
		array_push ($q,	' AND [tableid] = %s', 'e10.persons.persons', ' AND [valueString] = %s', $value);
		array_push ($q,	' AND [group] = %s', $group, ' AND property = %s', $id);
		array_push ($q, ' AND [persons].docState = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			return $r['recid'];
		}

		return 0;
	}

	protected function setInboxPersonFrom($personNdx)
	{
		if (!$this->inboxNdx)
			return;

		$exist = $this->db()->query('SELECT * FROM [e10_base_doclinks] WHERE [srcTableId] = %s', 'wkf.core.issues',
																' AND [dstTableId] = %s', 'e10.persons.persons',
																' AND [linkId] = %s', 'wkf-issues-from',
																' AND [srcRecId] = %i', $this->inboxNdx,
																' AND [dstRecId] = %i', $personNdx
																)->fetch();

		if ($exist)
			return;

		$newLink = [
			'linkId' => 'wkf-issues-from',
			'srcTableId' => 'wkf.core.issues', 'srcRecId' => $this->inboxNdx,
			'dstTableId' => 'e10.persons.persons', 'dstRecId' => $personNdx
		];

		$this->db()->query ('INSERT INTO e10_base_doclinks ', $newLink);
	}

	function checkVat($percents, &$row)
	{
		$dateTax = utils::createDateTime ($this->docHead['dateTax']);
		$percSettings = $this->app->cfgItem ('e10doc.taxes.'.'eu'.'.'.'cz'.'.taxPercents', NULL);
		forEach ($percSettings as $itm)
		{
			if ($itm['value'] != $percents)
				continue;

			$dateFrom = utils::createDateTime ($itm ['from']);
			$dateTo = utils::createDateTime ($itm ['to']);

			if (($dateFrom) && ($dateFrom > $dateTax))
				continue;
			if (($dateTo) && ($dateTo < $dateTax))
				continue;

			$taxCodeCfg = E10Utils::taxCodeCfg($this->app(), $itm['code']);
			if (!$taxCodeCfg)
				continue;

			if (isset($taxCodeCfg['dir']) && $taxCodeCfg['dir'] != 0)
				continue;

			$row['taxCode'] = $itm['code'];
			$row['taxRate'] = $taxCodeCfg['rate'];
			$row['taxPercents'] = $itm['value'];

			return;
		}
	}

	protected function loadPerson()
	{
		if ($this->personRecData)
			return;
		if (!isset($this->docHead['person']) || !$this->docHead['person'])
			return;
		$this->personRecData = $this->app()->loadItem($this->docHead['person'], 'e10.persons.persons');
	}

	public function createDocument($fromRecData, $checkNewRec = FALSE)
	{
		$this->createImport();

		if ($this->automaticImport)
		{
			$this->loadPerson();
			if (!$this->personRecData)
				return;
			if (!$this->personRecData['optBuyDocImport'])
				return;
		}

		if ($fromRecData)
		{
			$head = $fromRecData;
			foreach ($this->docHead as $key => $value)
				$head[$key] = $value;

			$this->docHead = $head;
		}

		if (!isset($this->docHead['dbCounter']))
		{
			$dbCounters = $this->app()->cfgItem ('e10.docs.dbCounters.'.$this->docHead['docType'], ['1' => []]);
			$this->docHead['dbCounter'] = key($dbCounters);
		}

		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		if ($checkNewRec)
			$tableDocs->checkNewRec($this->docHead);

		if (isset($this->docHead['inboxNdx']))
		{
			$this->inboxNdx = $this->docHead['inboxNdx'];
			unset($this->docHead['inboxNdx']);

			if (!$checkNewRec)
			{
				$tableDocs->checkInboxDocument($this->inboxNdx, $this->docHead);
			}
		}
		if (isset($this->docHead['ddfId']))
			unset($this->docHead['ddfId']);
		if (isset($this->docHead['ddfNdx']))
			unset($this->docHead['ddfNdx']);

		$this->saveDoc();

		if ($this->inboxNdx && isset($this->docHead['person']) && $this->docHead['person'])
			$this->setInboxPersonFrom($this->docHead['person']);
	}

	public function resetDocument($documentNdx)
	{
		$this->createImport();

		if (isset($this->docHead['ddfId']))
			unset($this->docHead['ddfId']);
		if (isset($this->docHead['ddfNdx']))
			unset($this->docHead['ddfNdx']);

		$this->replaceDocumentNdx = $documentNdx;

		$this->saveDoc();
	}

	function saveDoc ()
	{
		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$tableRows = new \E10Doc\Core\TableRows ($this->app);

		if ($this->replaceDocumentNdx !== 0)
			$this->docHead = $tableDocs->loadItem ($this->replaceDocumentNdx);

		if ($this->replaceDocumentNdx === 0)
		{
			$docNdx = $tableDocs->dbInsertRec ($this->docHead);
			$this->docHead['ndx'] = $docNdx;

			if ($this->inboxNdx)
			{
				$newLink = [
					'linkId' => 'e10docs-inbox',
					'srcTableId' => 'e10doc.core.heads', 'srcRecId' => $docNdx,
					'dstTableId' => 'wkf.core.issues', 'dstRecId' => $this->inboxNdx
				];
				$this->db()->query('INSERT INTO [e10_base_doclinks] ', $newLink);
			}
		}
		else
		{
			$tableDocs->dbUpdateRec ($this->docHead);
			$docNdx = $this->replaceDocumentNdx;
			$this->db()->query ("DELETE FROM [e10doc_core_rows] WHERE [document] = %i", $docNdx);
		}

		$f = $tableDocs->getTableForm ('edit', $docNdx);

		forEach ($this->docRows as $r)
		{
			$r['document'] = $docNdx;
			$tableRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
			$tableDocs->dbUpdateRec ($f->recData);

		$this->docRecData = $tableDocs->loadItem($f->recData['ndx']);
	}

	function applyRowSettings(&$row)
	{
		$rowsSettings = new \e10doc\helpers\RowsSettings($this->app());
		$rowsSettings->run ($row, $this->docHead);
	}

	protected function checkItem($srcRow, &$docRow)
	{
		if (isset($docRow['item']) && $docRow['item'])
			return;

		if ($this->personRecData && $this->personRecData['optBuyDocImportItem'])
			$docRow['item'] = $this->personRecData['optBuyDocImportItem'];
	}

	protected function searchItem($itemInfo, $srcRow, &$docRow)
	{
		if ($itemInfo['supplierCode'] !== '')
		{
			if ($this->searchItemBySupplierCode($itemInfo, $docRow))
				return;

			if (!$this->personRecData || !$this->personRecData['optBuyItemsImport'])
				return;

			$this->createItemFromRow($itemInfo, $srcRow, $docRow);
		}
	}

	protected function searchItemBySupplierCode($itemInfo, &$docRow)
	{
		$q = [];
		array_push($q, 'SELECT itemSuppliers.*, witems.itemType, witems.itemKind');
		array_push($q, ' FROM [e10_witems_itemSuppliers] AS itemSuppliers');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS witems ON itemSuppliers.[item] = witems.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND itemSuppliers.supplier = %i', $this->docHead['person']);
		array_push($q, ' AND itemSuppliers.itemId = %s', $itemInfo['supplierCode']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$docRow['item'] = $r['item'];

			$itemKind = intval($r['itemKind']);
			if ($itemKind === 1) // stock
				$docRow['operation'] = 1010102;

			return 1;
		}

		return 0;
	}

	protected function createItemFromRow($itemInfo, $srcRow, &$docRow)
	{
		/** @var \e10\witems\TableItems */
		$tableItems = $this->app()->table('e10.witems.items');

		/** @var \e10\witems\TableItemSuppliers */
		$tableItemSuppliers = $this->app()->table('e10.witems.itemSuppliers');

		$newItem = [];

		if ($itemInfo['manufacturerCode'] !== '')
			$newItem['manufacturerId'] = Str::upToLen($itemInfo['manufacturerCode'], 40);

		if ($itemInfo['itemFullName'] !== '')
			$newItem['fullName'] = Str::upToLen($itemInfo['itemFullName'], 120);
		if ($itemInfo['itemShortName'] !== '')
			$newItem['shortName'] = Str::upToLen($itemInfo['itemShortName'], 80);

		if (!count($newItem))
			return;

		if (isset($srcRow['unit']))
			$newItem['defaultUnit'] = $srcRow['unit'];

		if ($this->personRecData && $this->personRecData['optBuyItemsImportItemType'])
		{
			$itemTypeRecData = $this->app()->loadItem($this->personRecData['optBuyItemsImportItemType'], 'e10.witems.itemtypes');
			if ($itemTypeRecData)
			{
				$newItem['itemType'] = $this->personRecData['optBuyItemsImportItemType'];
				$newItem['type'] = $itemTypeRecData['id'];
				$newItem['itemKind'] = $itemTypeRecData['type'];
			}
		}

		$newItem['docState'] = 1000;
		$newItem['docStateMain'] = 0;

		$newItemNdx = $tableItems->dbInsertRec($newItem);
		$newItem = $tableItems->loadItem($newItemNdx);
		$tableItems->checkAfterSave2 ($newItem);

		if ($itemInfo['supplierCode'] !== '')
		{
			$newItemSupplier = [
				'item' => $newItemNdx,
				'supplier' => $this->docHead['person'],
				'rowOrder' => 1000,
				'itemId' => $itemInfo['supplierCode'],
			];
			if (isset($itemInfo['supplierItemUrl']) && $itemInfo['supplierItemUrl'] !== '')
				$newItemSupplier['url'] = $itemInfo['supplierItemUrl'];

			$tableItemSuppliers->dbInsertRec($newItemSupplier);
			$this->searchItemBySupplierCode($itemInfo, $docRow);
		}
		else
		{

		}
	}

	protected function updateInbox()
	{
		if (!$this->inboxNdx || !count($this->docHead))
			return;

		$inboxRecData = $this->app()->loadItem($this->inboxNdx, 'wkf.core.issues');

		$update = [];
		if (isset($this->docHead['docId']) && $this->docHead['docId'] !== '' && $inboxRecData['docId'] === '')
			$update['docId'] = $this->docHead['docId'];
		if (isset($this->docHead['symbol1']) && $this->docHead['symbol1'] !== '' && $inboxRecData['docSymbol1'] === '')
			$update['docSymbol1'] = $this->docHead['symbol1'];
		if (isset($this->docHead['symbol2']) && $this->docHead['symbol2'] !== '' && $inboxRecData['docSymbol2'] === '')
			$update['docSymbol2'] = $this->docHead['symbol2'];

		if (isset($this->docHead['dateIssue']) && !Utils::dateIsBlank($this->docHead['dateIssue']) && Utils::dateIsBlank($inboxRecData['docDateIssue']))
			$update['docDateIssue'] = $this->docHead['dateIssue'];
		if (isset($this->docHead['dateDue']) && !Utils::dateIsBlank($this->docHead['dateDue']) && Utils::dateIsBlank($inboxRecData['docDateDue']))
			$update['docDateDue'] = $this->docHead['dateDue'];
		if (isset($this->docHead['dateTax']) && !Utils::dateIsBlank($this->docHead['dateTax']) && Utils::dateIsBlank($inboxRecData['docDateTax']))
		{
			$update['docDateTax'] = $this->docHead['dateTax'];
			$update['docDateTaxDuty'] = $this->docHead['dateTax'];
		}

		if (isset($this->srcImpData['head']['money-to-pay']) && $this->srcImpData['head']['money-to-pay'] != 0 && $inboxRecData['docPrice'] == 0)
			$update['docPrice'] = $this->srcImpData['head']['money-to-pay'];

		if (isset($update['docPrice']) && $inboxRecData['docCurrency'] == 0)
		{
			$homeCurrency = utils::homeCurrency($this->app(), $this->docHead['dateIssue'] ?? utils::today());
			$update['docCurrency'] = World::currencyNdx($this->app(), $homeCurrency);
		}

		if (count($update))
			$this->db()->query('UPDATE [wkf_core_issues] SET ', $update, ' WHERE [ndx] = %i', $this->inboxNdx);
	}
}

<?php

namespace e10doc\ddf\core\libs;
use \e10\json, \e10\utils, \Shipard\Utils\Str;
use \e10doc\core\libs\E10Utils;
use \Shipard\Utils\World;
use \e10\base\libs\UtilsBase;

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

	var $importProtocol = ['head' => [], 'rows' => []];

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
			$dh = $tableDocs->loadItem ($this->replaceDocumentNdx);
			foreach ($this->docHead as $dhKey => $dhValue)
			{
				if ($dhKey === 'docState' || $dhKey === 'docStateMain')
					continue;
				$dh[$dhKey] = $dhValue;
			}
			$tableDocs->dbUpdateRec ($dh);
			$this->docHead = $tableDocs->loadItem ($this->replaceDocumentNdx);
			$docNdx = $this->replaceDocumentNdx;
			$this->db()->query ("DELETE FROM [e10doc_core_rows] WHERE [document] = %i", $docNdx);
		}

		$f = $tableDocs->getTableForm ('edit', $docNdx);

		forEach ($this->docRows as $r)
		{
			if (isset($r['!itemInfo']))
				unset($r['!itemInfo']);

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

	function applyDocsImportSettings(&$row)
	{
		$importSettings = new \e10doc\helpers\libs\DocsImportSettings($this->app());
		$importSettings->run ($row, $this->docHead);
	}

	protected function addRowsFromSettings()
	{
		$importSettings = new \e10doc\helpers\libs\DocsImportSettings($this->app());
		$importSettings->addRows ($this->docRows, $this->docHead, $this);
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
			$update['docPrice'] = $this->srcImpData['head']['money-to-pay']; // obsolete
		if (isset($this->srcImpData['head']['moneyToPay']) && $this->srcImpData['head']['moneyToPay'] != 0 && $inboxRecData['docPrice'] == 0)
			$update['docPrice'] = $this->srcImpData['head']['moneyToPay'];

		if (isset($update['docPrice']) && $inboxRecData['docCurrency'] == 0)
		{
			$homeCurrency = utils::homeCurrency($this->app(), $this->docHead['dateIssue'] ?? utils::today());
			$update['docCurrency'] = World::currencyNdx($this->app(), $homeCurrency);
		}

		if (count($update))
			$this->db()->query('UPDATE [wkf_core_issues] SET ', $update, ' WHERE [ndx] = %i', $this->inboxNdx);
	}

	protected function previewCode()
	{
		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);

		$paymentMethods = $this->app->cfgItem ('e10.docs.paymentMethods');
		$taxCalc = $tableDocs->columnInfoEnum ('taxCalc', 'cfgText');
		$taxType = $tableDocs->columnInfoEnum ('taxType', 'cfgText');
		$taxMethod = $tableDocs->columnInfoEnum ('taxMethod', 'cfgText');

		$personRecData = NULL;
		$personNdx = 0;
		if ($this->impData['head']['person'])
		{
			$personRecData = $this->app()->loadItem($this->impData['head']['person'], 'e10.persons.persons');
			$personNdx = $personRecData['ndx'];
		}

		$c = '';

		$fc = ($this->impData['head']['currency'] != $this->impData['head']['homeCurrency']);
		$scFC = strtoupper($this->impData['head']['currency']);
		$scHC = strtoupper($this->impData['head']['homeCurrency']);

		$c .= "<table class=' fullWidth'>";
		$c .= "<tr>";

			$c .= "<td class='width80' style='vertical-align: top;'>";
				$c .= '<h2>Faktura č. '.Utils::es($this->impData['head']['docId'] ?? '---').'</h2>';
				if (isset($this->impData['head']['title']))
				$c .= "<div class='pb1'>".Utils::es($this->impData['head']['title']).'</div>';
			$c .= "</td>";

			$c .= "<td class='width20 number' style='vertical-align: top;'>";
				if ($fc)
				{
					$c .= '<h2>'."<small class='e10-off'>".Utils::es($scHC).'</small> '.Utils::es($scFC).'</h2>';
					$c .= '1 '.Utils::es($scHC).' = '.strval($this->impData['head']['exchangeRate']).' '.Utils::es($scFC);
				}
				else
					$c .= '<h2>'.Utils::es($scFC).'</h2>';
			$c .= "</td>";

		$c .= "</tr>";
		$c .= "</table>";
		$c .= "<br/>";

		// -- persons
		$c .= "<table class='default fullWidth'>";
		$c .= "<tr>";
			$c .= "<td class='width50'>";
				$c .= "<span class='h2'>".Utils::es('Dodavatel').'</span>';
				$srcLabels = [];
				if (isset($this->importProtocol['person']['src']['oid']))
					$srcLabels [] = ['text' => 'IČ: '.$this->importProtocol['person']['src']['oid'], 'class' => 'label label-success pull-right'];
				if (isset($this->importProtocol['person']['src']['vatId']))
					$srcLabels [] = ['text' => 'DIČ: '.$this->importProtocol['person']['src']['vatId'], 'class' => 'label label-success pull-right'];
				if (isset($this->importProtocol['person']['src']['fullName']))
					$srcLabels [] = ['text' => $this->importProtocol['person']['src']['fullName'], 'class' => 'break'];
				$c .= $this->app()->ui()->composeTextLine($srcLabels);
			$c .= '</td>';
			$c .= '<td>';
				$c .= "<div class='h2'>".Utils::es('Odběratel').'</div>';
			$c .= "</td>";
		$c .= "</tr>";

		$c .= "<tr>";
			$c .= "<td class='width50'>";
				if ($personRecData)
				{
					$personLabel = [];
					$personLabel[] = [
						'text' => $personRecData['fullName'], 'suffix' => '#'.$personRecData['id'], 'class' => 'h2 block',
						'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk' => $personRecData['ndx'],
					];

					$c .= $this->app()->ui()->composeTextLine($personLabel);
				}
			$c .= '</td>';
			$c .= '<td>';
			$c .= '</td>';
		$c .= "</tr>";
		$c .= "</table>";

		// -- dates & co.
		$c .= '<br/>';
		$c .= "<table class='default fullWidth'>";
		$c .= "<tr>";
			$c .= "<td>".Utils::es('Datum vystavení').'</td>';
			$c .= "<td>".$this->previewCode_Date($this->impData['head'], 'dateIssue').'</td>';

			$c .= "<td>".Utils::es('Variabilní symbol').'</td>';
			$c .= "<td>".Utils::es($this->impData['head']['symbol1'] ?? '').'</td>';

			$c .= "<td>".Utils::es('Způsob úhrady').'</td>';
			$pm = $paymentMethods[$this->impData['head']['paymentMethod'] ?? 0] ?? NULL;
			$pmLabel = NULL;
			if ($pm)
				$pmLabel = ['text' => $pm['title'], 'icon' => $pm['icon'], 'class' => ''];
			else
				$pmLabel = ['text' => '?', 'class' => ''];
			$c .= "<td>".$this->app()->ui()->composeTextLine($pmLabel).'</td>';

		$c .= "</tr>";
		$c .= "<tr>";
			$c .= "<td>".Utils::es('DUZP / DPPD').'</td>';
			$c .= "<td>".$this->previewCode_Date($this->impData['head'], 'dateTax').'</td>';
			$c .= "<td>".Utils::es('Specifický symbol').'</td>';
			$c .= "<td>".Utils::es($this->impData['head']['symbol2'] ?? '').'</td>';

			$c .= "<td>".Utils::es('Výpočet daně').'</td>';
			$c .= "<td>".Utils::es($taxCalc[$this->impData['head']['taxCalc'] ?? 0] ?? '!').' / '.Utils::es($taxMethod[$this->impData['head']['taxMethod'] ?? 0] ?? '!').'</td>';
		$c .= "</tr>";
		$c .= "<tr>";
			$c .= "<td>".Utils::es('Datum splatnosti').'</td>';
			$c .= "<td>".$this->previewCode_Date($this->impData['head'], 'dateDue').'</td>';
			$c .= "<td>".Utils::es('Bankovní spojení').'</td>';
			$c .= "<td>".Utils::es($this->impData['head']['bankAccount'] ?? '').'</td>';
			$c .= "<td>".Utils::es('Typ daně').'</td>';
			$c .= "<td>".Utils::es($taxType[$this->impData['head']['taxType'] ?? 0] ?? '!').'</td>';
		$c .= "</tr>";
		$c .= "</table>";

		// -- rows
		$c .= '<br/>';
		$tr = [];
		$th = [
			'#' => '#', 'itemId' => 'Položka', 'itemInfo' => 'Obsah řádku', 'vatp' => ' %DPH',
			'quantity' => ' Mn.', 'priceItem' => ' Cena/pol.', 'priceAll' => '+Cena celk.'
		];

		foreach ($this->impData['rows'] as $r)
		{
			$itemRecData = NULL;
			if (isset($r['item']))
				$itemRecData = $this->app()->loadItem($r['item'], 'e10.witems.items');
			$rowItem = [];

			$rowItem['itemInfo'] = [];
			$rowItem['itemInfo'][] = ['text' => $r['text'], 'class' => 'block e10-bold'];
			if (isset($r['!itemInfo']))
			{
				$iiLabels = [];
				foreach ($r['!itemInfo'] as $iiKey => $iiValue)
				{
					if ($iiValue == '')
						continue;
					$iiLabels[] = ['text' => $iiValue, 'suffix' => $iiKey, 'class' => 'label label-default lh16'];
					if ($iiKey === 'supplierCode' && $personNdx && !intval($r['item'] ?? 0))
					{
						$iiLabels[] = [
							'text'=> '', 'docAction' => 'new', 'table' => 'e10doc.helpers.impDocsSettings', 'type' => 'span',
							'title' => 'Nové pravidlo na základě Kódu dodavatele',
							'actionClass' => 'label label-success', 'icon' => 'system/actionAdd', 'class' => 'label label-success',
							'addParams' => "__qryRowSupplierCodeValue=".Utils::es($iiValue)."&__qryRowSupplierCodeType=1"."&__qryHeadPerson={$personNdx}"
						];
					}
					if ($iiKey === 'itemFullName' && $personNdx && !intval($r['item'] ?? 0))
					{
						$iiLabels[] = [
							'text'=> '', 'docAction' => 'new', 'table' => 'e10doc.helpers.impDocsSettings', 'type' => 'span',
							'title' => 'Nové pravidlo na základě Textu řádku',
							'actionClass' => 'label label-success', 'icon' => 'system/actionAdd', 'class' => 'label label-success',
							'addParams' => "__qryRowTextValue=".Utils::es($iiValue)."&__qryRowTextType=1"."&__qryHeadPerson={$personNdx}"
						];
					}

				}
				if (count($iiLabels))
					$rowItem['itemInfo'] = array_merge($rowItem['itemInfo'], $iiLabels);
			}

			$quantity = $r['quantity'] ?? 1;
			$rowItem['quantity'] = strval($r['quantity'] ?? 1);

			$priceSource = intval($r['priceSource'] ?? 0);

			if ($priceSource === 0)
			{
				$rowItem['priceAll'] = $r['priceAll'] ?? 0.0;
				$rowItem['priceItem'] = Utils::nf($r['priceItem'] ?? 0, 2);
				$rowItem['_options']['cellClasses']['priceItem'] = 'e10-bold';
			}
			else
			{
				$rowItem['priceAll'] = $r['priceAll'] ?? 0.0;
				$rowItem['priceItem'] = $r['priceAll'] / $quantity;
				$rowItem['_options']['cellClasses']['priceAll'] = 'e10-bold';
			}


			$rowItem['vatp'] = strval($r['taxPercents']);

			if ($itemRecData)
			{
				$rowItem['itemId'] = ['text' => $itemRecData['id'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $itemRecData['ndx']];
			}

			$tr[] = $rowItem;
		}

		$tabRendeder = new \Shipard\Utils\TableRenderer($tr, $th, ['tableClass' => 'default fullWidth'], $this->app());
    $c .= $tabRendeder->render();

		return $c;
	}

	protected function previewCode_Date($src, $key)
	{
		if (!isset($src[$key]))
			return '-';
		$d = Utils::createDateTime($src[$key]);
		if (!$d)
			return '!';

		return Utils::datef($d, '%d');
	}


	protected function previewAtt()
	{
		$attRecData = $this->app()->loadItem($this->attachmentNdx, 'e10.base.attachments');
		$files = UtilsBase::loadAttachments ($this->app(), [$attRecData['recid']], $attRecData['tableid']);

		if (isset($files[$attRecData['recid']]))
		{
			$content = ['type' => 'attachments', 'attachments' => $files[$attRecData['recid']], 'downloadTitle' => 'Stáhnout'];
			return $content;
		}

		return NULL;
	}
}

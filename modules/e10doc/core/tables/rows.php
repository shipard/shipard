<?php

namespace e10doc\core;

use \e10\Application, \E10\utils, \E10\TableView;
use \E10\DbTable;
use \Shipard\Form\TableForm;
use \e10doc\core\libs\E10Utils;

/**
 * Řádky Dokladů
 *
 */

class TableRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.core.rows", "e10doc_core_rows", "Řádky dokladů");
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$ownerData = $this->ownerRecData($recData, $ownerData);
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $ownerData ['docType'], NULL);
		$docTaxCodes = E10Utils::docTaxCodes($this->app(), $ownerData);

		if (!isset($recData ['priceSource']))
			$recData ['priceSource'] = 0;
		if (!isset($recData ['operation']))
			$recData ['operation'] = 0;
		if (!isset($recData ['priceItem']))
			$recData ['priceItem'] = 0;
		if (!isset($recData ['quantity']))
			$recData ['quantity'] = 1;

		$operation = $this->app()->cfgItem ('e10.docs.operations.' . $recData ['operation'], FALSE);
		$currencyMode = utils::param ($operation, 'currencyMode', '');

    //Měny a Kurz + Způsob výpočtu DPH
		if ($currencyMode === '')
    	$recData ['currency'] = $ownerData ['currency'];
    $recData ['homeCurrency'] = $ownerData ['homeCurrency'];

		if ($ownerData ['docType'] !== 'bank')
			$recData ['exchangeRate'] = $ownerData ['exchangeRate'];

    $recData ['taxCalc'] = $ownerData ['taxCalc'];

    // DUZP a Daňové období
    $recData ['dateTax'] = $ownerData ['dateTax'];
    $recData ['taxPeriod'] = $ownerData ['taxPeriod'];

    // Účetní datum a Fiskální období
    $recData ['dateAccounting'] = $ownerData ['dateAccounting'];
    $recData ['fiscalYear'] = $ownerData ['fiscalYear'];
    $recData ['fiscalMonth'] = $ownerData ['fiscalMonth'];

    // Variabilní a Specifický symbol + Datum splatnosti
    //$recData ['symbol1'] = $ownerData ['symbol1'];
    //$recData ['symbol2'] = $ownerData ['symbol2'];
    //$recData ['dateDue'] = $ownerData ['dateDue'];
    // Osoba
    //$recData ['person'] = $ownerData ['person'];


		// item type
		if (!isset ($recData ['item']) || $recData ['item'] === '')
			$recData ['item'] = 0;
		if (!isset ($recData ['itemIsSet']) || $recData ['itemIsSet'] === '')
			$recData ['itemIsSet'] = 0;

		if (!isset ($recData ['itemType']))
			$recData ['itemType'] = '';

		if ($recData ['itemType'] == '')
		{
			if ($recData ['item'] != 0)
			{
				$q = "SELECT [type], [useBalance], [isSet], [vatRate] from [e10_witems_items] WHERE ndx = %i";
				$item = $this->app()->db()->query($q, $recData ['item'])->fetch ();
				if ($item)
				{
					$recData ['itemType'] = $item ['type'];
					$recData ['itemBalance'] = $item ['useBalance'];
					$recData ['itemIsSet'] = ($item ['isSet']) ? 1 : 0;
					if (!isset($recData ['_fixTaxCode']))
					{
						$recData ['taxRate'] = $item['vatRate'];
						$recData ['taxCode'] = E10Utils::taxCodeForDocRow($this->app(), $ownerData, $this->taxDir($recData, $ownerData), $item['vatRate']);
					}
					else
					{
						unset ($recData ['_fixTaxCode']);
					}
				}
			}
		}

		// -- subColumns
		if (isset($recData['rowVds']) && $recData['rowVds'])
		{
			$sci = $this->subColumnsInfo ($recData, 'rowData');
			if ($sci && isset($sci['computeClass']))
			{
				$cc = $this->app()->createObject($sci['computeClass']);
				if ($cc)
					$cc->checkBeforeSave($recData, $ownerData, $sci);
			}
		}

		// Výpočet cen v řádku...
		$recData ['taxBaseHcCorr'] = 0;
		if ($recData ['priceSource'] == 0)
			$recData ['priceAll'] = round ($recData ['priceItem'] * $recData ['quantity'], 2);
		else
		{
			if ($recData ['quantity'] != 0.0)
				$recData ['priceItem'] = round($recData ['priceAll'] / $recData ['quantity'], 4);
			else
				$recData ['priceItem'] = 0.0;
		}
		if (!isset($recData['taxCode']))
			$recData ['taxCode'] = $this->defaultTaxCode ($recData, $ownerData);

    $cfgTaxCode = $docTaxCodes[$recData['taxCode']] ?? NULL;
		if (!$cfgTaxCode || !$this->checkTaxCode ($recData['taxCode'], $cfgTaxCode, $recData, $ownerData))
		{
			$recData ['taxCode'] = $this->defaultTaxCode ($recData, $ownerData);
			$cfgTaxCode = $docTaxCodes[$recData['taxCode']] ?? NULL;
		}

		$zeroTax = 0;
		if (isset ($cfgTaxCode ['zeroTax']) && ($cfgTaxCode ['zeroTax'] == 1))
			$zeroTax = 1;
		$noPayTax = 0;
		if (isset ($cfgTaxCode ['noPayTax']) && ($cfgTaxCode ['noPayTax'] == 1))
			$noPayTax = 1;
		$taxRoundMethod = $this->app()->cfgItem ('e10.docs.taxRoundMethods.' . $ownerData['taxRoundMethod'], 0);

		if ($ownerData['docType'] === 'bank' || $ownerData['docType'] === 'cmnbkp')
		{
			if (isset($recData ['credit']) && $recData ['credit'] != 0.0)
				$recData ['priceAll'] = $recData ['credit'];
			else
				$recData ['priceAll'] = $recData ['debit'];
		}
		if ($ownerData['docType'] === 'bankorder')
		{
			$recData ['priceAll'] = $recData ['priceItem'];
			if (isset($recData ['operation']) && $recData ['operation'] == 1030102)
				$recData ['credit'] = $recData ['priceAll'];
			else
				$recData ['debit'] = $recData ['priceAll'];
		}

		if ($ownerData ['taxPayer'])
		{
			$taxDate = E10Utils::headsTaxDate ($ownerData, $recData);
			$cfgTaxCode = $docTaxCodes[$recData['taxCode']] ?? NULL;
			//if (!$cfgTaxCode)
			//	error_log("INVALID TAX CODE: ".json_encode($recData['taxCode'])." - ".json_encode($recData));
			$recData ['taxRate'] = $cfgTaxCode ['rate'];
			$recData ['taxPercents'] = $this->taxPercent ($recData ['taxCode'], $taxDate);
		}
		else
		{
			$recData ['taxCode'] = '';
			$recData ['taxRate'] = 0;
			$recData ['taxPercents'] = 0;
		}

    switch (intval ($recData ['taxCalc']))
    {
      case 0: // bez daně
            $recData ['taxBase'] = $recData ['priceAll'];
            $recData ['tax'] = 0;
            $recData ['priceTotal'] = $recData ['priceAll'];
            break;
      case 1: // ze základu
            $recData ['taxBase'] = $recData ['priceAll'];
            if ($zeroTax)
              $recData ['tax'] = 0.0;
            else
              $recData ['tax'] = utils::e10round ($recData ['taxBase'] * ($recData ['taxPercents'] / 100.0), $taxRoundMethod);
            $recData ['priceTotal'] = $recData ['taxBase'];
						if (!$noPayTax)
							$recData ['priceTotal'] += $recData ['tax'];
            break;
      case 2: // z ceny celkem KOEF
						{
							$k = round(($recData ['taxPercents'] / ($recData ['taxPercents'] + 100)), 4);
							$recData ['tax'] = utils::e10round(($recData ['priceAll'] * $k), $taxRoundMethod);
							$recData ['taxBase'] = $recData ['priceAll'] - $recData ['tax'];
							if ($noPayTax)
								$recData ['priceTotal'] = $recData ['taxBase'];
							else
								$recData ['priceTotal'] = $recData ['priceAll'];
						}
						break;
			case 3: // z ceny celkem
						{
							$k = round(((100 + $recData ['taxPercents']) / 100), 2);
							$recData ['taxBase'] = utils::e10round(($recData ['priceAll'] / $k), $taxRoundMethod);
							$recData ['tax'] = $recData ['priceAll'] - $recData ['taxBase'];
							if ($noPayTax)
								$recData ['priceTotal'] = $recData ['taxBase'];
							else
								$recData ['priceTotal'] = $recData ['priceAll'];
						}
        		break;
		}

		// home currency
		if (!isset ($recData ['exchangeRate']) || $recData ['exchangeRate'] == 0.0)
			$recData ['exchangeRate'] = 1.0;
		if (!isset ($recData ['credit']))
			$recData ['credit'] = 0.0;
		if (!isset ($recData ['debit']))
			$recData ['debit'] = 0.0;
		$recData ['creditHc'] = round (($recData ['credit'] * $recData ['exchangeRate']), 2);
		$recData ['debitHc'] = round (($recData ['debit'] * $recData ['exchangeRate']), 2);
		$recData ['priceItemHc'] = round (($recData ['priceItem'] * $recData ['exchangeRate']), 2);
    $recData ['priceAllHc'] = round (($recData ['priceAll'] * $recData ['exchangeRate']), 2);
    $recData ['taxBaseHc'] = round (($recData ['taxBase'] * $recData ['exchangeRate']), 2);
    $recData ['taxHc'] = round (($recData ['tax'] * $recData ['exchangeRate']), 2);
		$recData ['priceTotalHc'] = round (($recData ['priceTotal'] * $recData ['exchangeRate']), 2);

		// skladový pohyb
    $recData ['invDirection'] = 0;
		if ($recData ['itemType'] != '')
		{
			$itemType = $this->app()->cfgItem ('e10.witems.types.' . $recData['itemType'], NULL);
			if ($itemType)
			{ // 			"enumValues": {"0": "Služba", "1": "Zásoba", "2": "Účetní položka", "3": "Ostatní"}},
				if ($itemType['kind'] == 1 && $ownerData['warehouse'] != 0)
				{
					if ($docType && isset($docType['invDirection']))
						$recData ['invDirection'] = $docType['invDirection'];
				}
			}
		}

		// -- operation
		if (isset($ownerData['activity']) && $ownerData['activity'] !== '')
		{
			$activity = $docType['activities'][$ownerData['activity']];
			if (isset($activity['operation']))
				$recData ['operation'] = $activity['operation'];
		}

		if (!isset($recData ['operation']))
			$recData ['operation'] = $this->defaultOperation ($recData, $ownerData);

		$op = $this->app()->cfgItem ('e10.docs.operations.' . $recData ['operation'], FALSE);

		if (!$op || !$this->checkOperation ($op, $recData, $ownerData))
		{
			$recData ['operation'] = $this->defaultOperation ($recData, $ownerData);
			$op = $this->app()->cfgItem ('e10.docs.operations.' . $recData ['operation'], FALSE);
		}

		if ($recData ['invDirection'] === 0 && isset ($op['invDirection'])  && $ownerData['warehouse'] != 0)
			$recData ['invDirection'] = $op['invDirection'];

		if (isset($op['setTaxBase']))
		{
			switch ($op['setTaxBase'])
			{
				case  'priceItem':
								$recData ['taxBase'] = $recData ['priceItem'];
								$recData ['taxBaseHc'] = $recData ['priceItemHc'];
								break;
			}
		}
		if (isset($op['setDebit']))
		{
			switch ($op['setDebit'])
			{
				case  'priceItem':
								$recData ['debit'] = $recData ['priceItem'];
								$recData ['debitHc'] = $recData ['priceItemHc'];
								break;
			}
		}
		if (isset($op['setCredit']))
		{
			switch ($op['setCredit'])
			{
				case  'priceItem':
								$recData ['credit'] = $recData ['priceItem'];
								$recData ['creditHc'] = $recData ['priceItemHc'];
								break;
			}
		}

		// -- bank
		if ($ownerData['docType'] === 'bank')
		{
			if (!isset($recData ['dateDue']) || utils::dateIsBlank($recData ['dateDue']))
				$recData ['dateDue'] = $ownerData['datePeriodBegin'];
			if (!isset ($recData['bankRequestCurrency']) || $recData['bankRequestCurrency'] === '')
				$recData['bankRequestCurrency'] = $ownerData['currency'];
			if ($recData['bankRequestCurrency'] === $ownerData['currency'])
				$recData['bankRequestAmount'] = $recData['taxBase'];
		} else
		if ($ownerData['docType'] === 'cash')
		{
			if ($ownerData['collectingDoc'] === 0)
				$recData ['person'] = $ownerData['person'];
			$recData ['dateDue'] = $ownerData['dateAccounting'];
		}

		// -- weight
		if (isset ($recData ['unit']) && $recData ['unit'] != '')
		{
			$unit = $this->app()->cfgItem ('e10.witems.units.'.$recData ['unit'], FALSE);
			if ($unit)
			{
				if (isset ($unit['kind']) && $unit['kind'] === 'weight')
				{
					$recData ['weightNet'] = $recData ['quantity'] * $unit['coef'];
					$recData ['weightGross'] = $recData ['quantity'] * $unit['coef'];
				}
			}
		}

		if ($recData ['invDirection'] != 0)
		{ // 1: in, 2: out
			$recData ['invPrice'] = $recData ['taxBaseHc'];
			if ($ownerData['docType'] === 'purchase' && $recData ['invDirection'] === 1 && $recData ['invPrice'] < 0.0)
				$recData ['invPrice'] = 0.0;
		}

		// -- costs
		if (isset($op['costOff']))
		{
			$recData['costBase'] = 0.0;
			$recData['costTotal'] = 0.0;
			$recData['costBaseHc'] = 0.0;
			$recData['costTotalHc'] = 0.0;
		}
		else
		{
			$recData['costBase'] = $recData ['taxBase'];
			$recData['costTotal'] = $recData ['priceTotal'];
			$recData['costBaseHc'] = $recData ['taxBaseHc'];
			$recData['costTotalHc'] = $recData ['priceTotalHc'];
		}

		// -- other
		if ((!isset($recData ['centre']) || $recData ['centre'] == 0) && isset($ownerData ['centre']) && $ownerData ['centre'] != 0)
			$recData ['centre'] = $ownerData ['centre'];
		if ((!isset($recData ['project']) || $recData ['project'] == 0) && isset($ownerData ['project']) && $ownerData ['project'] != 0)
			$recData ['project'] = $ownerData ['project'];
		if ((!isset($recData ['workOrder']) || $recData ['workOrder'] == 0) && isset($ownerData ['workOrder']) && $ownerData ['workOrder'] != 0)
			$recData ['workOrder'] = $ownerData ['workOrder'];
		if ((!isset($recData ['property']) || $recData ['property'] == 0) && isset($ownerData ['property']) && $ownerData ['property'] != 0)
			$recData ['property'] = $ownerData ['property'];

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkOperation ($operation, $recData, $ownerRecData)
	{
		if ($operation['title'][0] === '*')
			return FALSE;

		if (isset ($operation['docTypes']) && (!in_array($ownerRecData['docType'], $operation['docTypes']) && !isset ($operation['docTypes'][$ownerRecData['docType']])))
			return FALSE;
		$dd = $this->docDir ($recData, $ownerRecData);
		if (isset ($operation['docDir']) && ($dd !== FALSE && $dd != $operation['docDir'] && $operation['docDir'] !== 0))
			return FALSE;
		if (isset ($operation['activities']) && (!in_array($ownerRecData['activity'], $operation['activities'])))
			return FALSE;

		if ($ownerRecData['docType'] === 'purchase' || $ownerRecData['docType'] === 'cashreg')
		{
			if (isset ($operation['itemType']))
			{
				if ($recData['item'] != 0)
				{
					$itemType = $this->app()->cfgItem ('e10.witems.types.' . $recData['itemType'], NULL);
					if (is_array($operation['itemType']) && !in_array($itemType['kind'], $operation['itemType']))
						return FALSE;
					if (is_int($operation['itemType']) && $itemType['kind'] != $operation['itemType'])
						return FALSE;
				}
			}
		}
		return TRUE;
	}

	public function checkTaxCode ($taxCodeKey, $taxCode, $recData, $ownerRecData)
	{
		if ($ownerRecData['taxCalc'] === 0)
			return FALSE;

		$docType = $this->app()->cfgItem ('e10.docs.types.' . $ownerRecData['docType'], NULL);
		if (!$docType)
			return TRUE;

		if (!isset ($docType ['taxDir']) || !isset($taxCode ['dir']))
			return TRUE;
		if (isset ($cfgItem ['hidden']) && $taxCode ['hidden'] == 1)
			return FALSE;
		if ($this->taxDir ($recData, $ownerRecData) != $taxCode ['dir'])
			return FALSE;
		if ($ownerRecData ['taxType'] != $taxCode ['type'])
			return FALSE;

		$taxDate = E10Utils::headsTaxDate ($ownerRecData, $recData);
		$tp = $this->taxPercent ($taxCodeKey, $taxDate);
		if ($tp === FALSE)
			return FALSE;

		return TRUE;
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'operation' && $form)
			return $this->columnInfoEnumOperations ($form->recData, $form->option ('ownerRecData'));

		if ($columnId === 'taxCode' && $form)
			return $this->columnInfoEnumTaxCodes($form->recData, $form->option ('ownerRecData'), $form);

		if ($columnId === 'priceSource' && $form)
			return [0 => 'cena / jedn.', 1 => 'cena celkem'];

		return parent::columnInfoEnum ($columnId, $valueType = 'cfgText', $form);
	}

	public function columnInfoEnumOperations ($recData, $ownerRecData)
	{
		$docType = $ownerRecData['docType'];
		$enumCfg = $this->app()->cfgItem('e10.docs.operations');
		$idx = 1;
		$buf = array();
		forEach ($enumCfg as $key => $item)
		{
			if (!$this->checkOperation ($item, $recData, $ownerRecData))
				continue;

			$title = (isset ($item['docTypes'][$docType]['title'])) ? $item['docTypes'][$docType]['title'] : $item['title'];

			if (isset ($item['docTypes'][$docType]['order']))
				$order = $item['docTypes'][$docType]['order'];
			else
				if (isset($item['order']))
					$order = $item['order'];
				else
					$order = 100000*$idx;

			$buf [$key] = array ('key' => $key, 'title' => $title, 'order' => $order);

			$idx++;
		}

		$buf = \E10\sortByOneKey($buf, 'order');
		$res = array();
		forEach ($buf as $b)
			$res[$b['key']] = $b['title'];

		return $res;
	}

	public function columnInfoEnumTaxCodes ($recData, $ownerRecData, $form)
	{
		$taxDate = E10Utils::headsTaxDate ($ownerRecData, $recData);
		$taxCodes = E10Utils::docTaxCodes($this->app(), $ownerRecData);
		$enum = [];
		foreach ($taxCodes as $tcId => $tc)
		{
			if (!$this->columnInfoEnumTest ('taxCode', $tcId, $tc, $form))
				continue;

			$taxPercents = $this->taxPercent($tcId, $taxDate);
			$enum[$tcId] = $tc['name'].': '.$taxPercents.' %';
		}

		return $enum;
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;
		$ownerRecData = $form->option ('ownerRecData');

		if ($columnId === 'taxCode')
			return $this->checkTaxCode ($cfgKey, $cfgItem, $form->recData, $ownerRecData);

		if ($columnId === 'operation')
			return $this->checkOperation ($cfgItem, $form->recData, $ownerRecData);

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function defaultOperation ($recData, $ownerRecData)
	{
		$ops = $this->columnInfoEnumOperations ($recData, $ownerRecData);
		return key($ops);
	}

	public function defaultTaxCode ($recData, $ownerRecData)
	{
		if ($ownerRecData['taxCalc'] == 0)
			return 'EU'.strtoupper(E10Utils::docTaxCountryId($this->app(), $ownerRecData)).'000';

		$taxCodes = E10Utils::docTaxCodes($this->app(), $ownerRecData);
		if ($taxCodes)
		{
			forEach ($taxCodes as $codeId => $code)
			{
				if ($this->checkTaxCode($codeId, $code, $recData, $ownerRecData))
					return $codeId;
			}
		}
		return 'EUCZ000';
	}

	public function docDir ($recData, $ownerRecData)
	{
		// TODO: refactor to E10Utils::docRowDir
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $ownerRecData['docType']);
		if (isset ($docType ['docDir']) && $docType ['docDir'] != 0)
			return $docType ['docDir'];

		if ($ownerRecData['docType'] == 'cash')
		{
			if ($ownerRecData ['cashBoxDir'] == 1) // příjem
				return 2;
			return 1;
		}

		if ($ownerRecData['docType'] === 'bank' || $ownerRecData['docType'] === 'cmnbkp')
		{
			if ($recData ['debit'] != 0.0)
				return 1;
			if ($recData ['credit'] != 0.0)
				return 2;
			return FALSE;
		}

		return 0;
	}

	public function taxDir ($recData, $ownerRecData)
	{
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $ownerRecData['docType']);
		if ($ownerRecData['docType'] == 'cash')
		{
			if ($ownerRecData ['cashBoxDir'] == 1) // příjem
				return 1;
			return 0;
		}
		return $docType ['taxDir'];
	}

	public function taxPercent ($taxCode, $date)
	{
		return E10Utils::taxPercent($this->app(), $taxCode, $date);
	}

	public function formId ($recData, $ownerRecData = NULL, $operation = 'edit')
	{
		if ($ownerRecData)
		{
			$docType = $this->app()->cfgItem('e10.docs.types.' . $ownerRecData['docType']);
			return $docType['classId'];
		}

		$document = $this->db()->query ('SELECT docType FROM [e10doc_core_heads] WHERE ndx = %i', $recData['document'])->fetch();
		if ($document)
		{
			$docType = $this->app()->cfgItem('e10.docs.types.' . $document['docType']);
			return $docType['classId'];
		}

		return 'default';
	}

	public function ownerRecData ($recData, $suggestedOwnerRecData = NULL)
	{
		if ($suggestedOwnerRecData)
			return $suggestedOwnerRecData;

		$head = $this->db()->query('SELECT * FROM [e10doc_core_heads] WHERE ndx = %i', $recData['document'])->fetch();
		if ($head)
			return $head->toArray();

		return parent::ownerRecData ($recData, $suggestedOwnerRecData);
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'rowData')
		{
			if (!isset($recData['rowVds']) || !$recData['rowVds'])
				return FALSE;

			$vds = $this->db()->query ('SELECT * FROM [vds_base_defs] WHERE [ndx] = %i', $recData['rowVds'])->fetch();
			if (!$vds)
				return FALSE;

			$sc = json_decode($vds['structure'], TRUE);
			if (!$sc || !isset($sc['fields']))
				return FALSE;

			return $sc['fields'];
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}
}


/**
 * Prohlížeč řádků dokladu
 *
 */

class ViewDocumentRows extends \E10\TableView
{
	var $itemsTypes;
	var $itemsUnits;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		if ($this->queryParam ('document'))
			$this->addAddParam ('document', $this->queryParam ('document'));

		$this->itemsTypes = $this->app()->cfgItem ('e10.witems.types');
		$this->itemsUnits = $this->app()->cfgItem ('e10.witems.units');

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['text'];
		$listItem ['t2'] = $this->itemsTypes [$item['itemType']]['.text'];
		$listItem ['i1'] = utils::nf ($item ['quantity']) . ' ' . $this->itemsUnits[$item['rowUnit']]['shortcut'];
		return $listItem;
	}


	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT [rows].ndx as ndx, [rows].item as item, [rows].[text] as [text], [rows].quantity as quantity,
										[rows].taxBase as taxBase, [rows].priceItem as priceItem, [rows].priceAll as priceAll, items.[type] as itemType, [rows].[unit] as rowUnit
						FROM e10doc_core_rows as [rows]
						RIGHT JOIN e10_witems_items as items on (items.ndx = [rows].item) WHERE 1";

		if ($fts != '')
		{
 			array_push ($q, " AND [rows].[text] LIKE %s", '%'.$fts.'%');
		}

 		array_push ($q, " AND [rows].document = %i", $this->queryParam ('document'));
 		array_push ($q, " ORDER BY [rows].ndx" . $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return array();
	}
} // class ViewDocumentRows


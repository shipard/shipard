<?php

namespace E10Doc\Debs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \E10\TableViewDetail, \E10\utils, E10\Utility, \e10\str;
use \e10doc\core\libs\E10Utils;


/**
 * Class docAccounting
 * @package E10Doc\Debs
 */

class docAccounting extends Utility
{
	var $docHead;
	var $docFiscalMonth;
	var $docTaxRegCfg;
	var $docAccounting = NULL;
	var $accOpts;
	var $accMethodId = '';
	var $methods;
	var $docJournal = array();
	var $sumDebit = 0;
	var $sumCredit = 0;
	var $cntSrcDocRows = 0;

	CONST acrDefault = 20;

	protected function incRow ($row, $enableZeroMoney = 0)
	{
		if ($row['money'] == 0.00 && !$enableZeroMoney)
			return;

		if (!isset($row['accountId']) || $row['accountId'] === FALSE)
		{
			$this->addMessage('Účet nenalezen');
			$row['accountId'] = '999999';
		}

		if ($row['side'] === 0)
		{
			$row['accountDrId'] = $row['accountId'];
			$row['moneyDr'] = $row['money'];
			$this->sumDebit += $row['money'];
		}
		else
		{
			$row['accountCrId'] = $row['accountId'];
			$row['moneyCr'] = $row['money'];
			$this->sumCredit += $row['money'];
		}

		$grouping = utils::param ($this->docAccounting, 'groupBy', 'default');
		switch ($grouping)
		{
			case 'default':
				$rowId = $row['accRing'].'_'.$row['side'].'_'.$row['accountId'].'_'.$row['centre'].'_'.$row['project'].'_'.$row['workOrder'].'_'.$row['property'].'_'.
								 $row['symbol1'].'_'.$row['symbol2'].'_'.$row['person'].'_'.$row['balance'].'_'.$row['cashBookId'];
				break;
			case 'off':
				$rowId = '__'.count ($this->docJournal);
				break;
		}

		if (isset($this->docJournal[$rowId]))
		{
			$this->docJournal[$rowId]['money'] += $row ['money'];
			if ($row['side'] === 0)
				$this->docJournal[$rowId]['moneyDr'] += $row ['moneyDr'];
			else
				$this->docJournal[$rowId]['moneyCr'] += $row ['moneyCr'];
		}
		else
		{
			$row['document'] = $this->docHead['ndx'];
			$row['docType'] = $this->docHead['docType'];
			$row['docNumber'] = $this->docHead['docNumber'];

			if (!isset($row['dateAccounting']))
				$row['dateAccounting'] = $this->docHead['dateAccounting'];

			$row['fiscalYear'] = $this->docHead['fiscalYear'];
			$row['fiscalMonth'] = $this->docHead['fiscalMonth'];
			$row['fiscalType'] = $this->docFiscalMonth['fiscalType'];

			$this->docJournal[$rowId] = $row;
		}
	}

	protected function addFromHead ($step, $firstRowId)
	{
		if (isset($step['query']) && !$this->testQuery($step['query'], $this->docHead))
			return;

		if (isset($step['method']) && !in_array($step['method'], $this->methods))
			return;

		if (!$this->testCash($step))
			return;
		if (!$this->testDocDir($step))
			return;

		$newRow = array ();
		$newRow['accRing'] = isset($step['accRing']) ? $step['accRing'] : self::acrDefault;

		$this->searchAccountHead ($step, $this->docHead, $newRow);
		$col = utils::param($step, 'col', 'toPay');

		switch ($col)
		{
			case	'toPay':
							$newRow ['money'] = $this->docHead['toPayHc'];
							break;
			case	'rounding':
							$newRow ['money'] = $this->docHead['roundingHc'];
							break;
			case	'debit':
							$newRow ['money'] = $this->docHead['debitHc'];
							break;
			case	'credit':
							$newRow ['money'] = $this->docHead['creditHc'];
							break;
		}

		$newRow ['centre'] = $this->docHead['centre'];
		$newRow ['project'] = $this->docHead['project'];
		$newRow ['workOrder'] = $this->docHead['workOrder'];
		$newRow ['property'] = $this->docHead['property'];

		$newRow ['person'] = $this->docHead['person'];
		if (isset ($step['balanceRequest']) && $step['balanceRequest'] === 1000)
			$newRow ['person'] = $this->docHead['personBalance'];

		$balanceRequest = utils::param($step, 'balanceRequest', 0);
		$balancePayment = utils::param($step, 'balancePayment', 0);
		if ($balanceRequest || $balancePayment)
		{
			$newRow ['symbol1'] = $this->docHead['symbol1'];
			$newRow ['symbol2'] = $this->docHead['symbol2'];
			$newRow ['balance'] = ($balanceRequest != 0) ? $balanceRequest : $balancePayment;
		}
		else
		{
			$newRow ['symbol1'] = '';
			$newRow ['symbol2'] = '';
			$newRow ['balance'] = 0;
		}
		$newRow ['side'] = utils::param($step, 'side', 0);

		$newRow ['cashBookId'] = 0;
		if (isset($step['cashBook']) && $step['cashBook'] == 2)
			$newRow ['cashBookId'] = $firstRowId;

		$newRow ['text'] = utils::param($step, 'text', $this->docHead['title']);

		if (!$this->testMoney($step, $newRow))
			return;

		$this->incRow($newRow);
	}

	/*
	protected function addFromRows ($step)
	{
		$q = "SELECT rows.*, items.debsAccountId as itemDebsAccountId, items.debsGroup as itemDebsGroup FROM [e10doc_core_rows] as rows
					LEFT JOIN e10_witems_items as items ON rows.item = items.ndx
					WHERE [document] = %i ORDER by ndx";

		$docRows = $this->db()->query ($q, $this->docHead['ndx']);
		forEach ($docRows as $r)
		{
			$this->doRowRecord ($step, $r);
		}
	}
	*/

	protected function addFromVAT ($step, $firstRowId)
	{
		$docRows = $this->db()->query ("SELECT * FROM [e10doc_core_taxes] WHERE [document] = %i ORDER by ndx", $this->docHead['ndx']);
		forEach ($docRows as $r)
		{
			$newRow = array ();
			$newRow['accRing'] = isset($step['accRing']) ? $step['accRing'] : self::acrDefault;

			if (isset($step['query']) && !$this->testQuery($step['query'], $r))
				continue;
			if (isset($step['method']) && !in_array($step['method'], $this->methods))
				return;
			if (!$this->testCash($step))
				continue;
			if (!$this->testDocDir($step))
				continue;

			$taxCode = E10Utils::taxCodeCfg($this->app, $r['taxCode']);

			$this->searchAccountVAT ($step, $r, $newRow);
			$newRow ['money'] = $r['sumTaxHc'];
			$newRow ['centre'] = $this->docHead['centre'];
			$newRow ['project'] = $this->docHead['project'];
			$newRow ['workOrder'] = $this->docHead['workOrder'];
			$newRow ['property'] = $this->docHead['property'];
			$newRow ['text'] = 'DPH: '.($taxCode['fullName'] ?? 'Chybný kód daně `'.$r['taxCode'].'`');
			$newRow ['side'] = utils::param($step, 'side', 1);

			if (isset ($taxCode['hidden'])) // place 'reversed' tax codes to other side
				$newRow ['side'] = ($newRow ['side'] == 1) ? 0 : 1;

			$newRow ['cashBookId'] = 0;
			if (isset($step['cashBook']) && $step['cashBook'] == 2)
				$newRow ['cashBookId'] = $firstRowId;

			$newRow ['symbol1'] = $this->docHead['symbol1'];
			$newRow ['symbol2'] = $this->docHead['symbol2'];
			$newRow ['symbol3'] = '';
			$newRow ['person'] = $this->docHead['person'];
			$newRow ['balance'] = 0;

			if (!$this->testMoney($step, $newRow))
				continue;

			$this->incRow($newRow);
		}
	}

	protected function createJournal ()
	{
		forEach ($this->accOpts['documents'] as $doc)
		{
			if ($doc['docType'] !== $this->docHead['docType'])
				continue;
			if (isset ($doc['docKind']) && $doc['docKind'] !== $this->docHead['docKind'])
				continue;
			if (isset ($doc['activity']) && $doc['activity'] !== $this->docHead['activity'])
				continue;

			$this->docAccounting = $doc;
			break;
		}

		if ($this->docAccounting === NULL)
		{
			$this->addMessage("Nelze najít nastavení zaúčtování dokladu.");
			return;
		}

		$this->createJournalRows ();
	}

	protected function createJournalRows ()
	{
		$q = [];
		array_push($q, 'SELECT [rows].*, items.debsAccountId as itemDebsAccountId, items.debsGroup as itemDebsGroup ');
		array_push($q, ' FROM [e10doc_core_rows] as [rows]');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [rows].item = items.ndx');
		array_push($q, ' WHERE [document] = %i', $this->docHead['ndx']);
		array_push($q, ' ORDER by rowOrder, ndx');

		$docRows = $this->db()->query ($q);
		$firstRowId = 0;
		$this->cntSrcDocRows = 0;
		forEach ($docRows as $r)
		{
			if (!$firstRowId)
				$firstRowId = $r['ndx'];
			forEach ($this->docAccounting['accounting'] as $step)
			{
				if ($step['src'] === 'rows')
					$this->doRowRecord ($step, $r, $firstRowId);
				else
				if ($step['src'] === 'balanceRows')
					$this->doBalanceRows ($step, $r, $firstRowId);
			}

			$this->cntSrcDocRows++;
		}

		forEach ($this->docAccounting['accounting'] as $step)
		{
			switch ($step['src'])
			{
				case	'head': $this->addFromHead ($step, $firstRowId); break;
				case	'vat': 	$this->addFromVAT ($step, $firstRowId); break;
			}
		}
	}

	protected function doRowRecord ($step, $r, $firstRowId)
	{
		if (isset ($step['operation']) && ($r['operation'] != $step['operation']))
			return;
		if (isset ($step['operations']) && !in_array($r['operation'], $step['operations']))
			return;
		if (isset($step['query']) && !$this->testQuery($step['query'], $r))
			return;
		if (isset($step['method']) && !in_array($step['method'], $this->methods))
			return;
		if (!$this->testCash($step))
			return;
		if (!$this->testDocDir($step, $r))
			return;

		$newRow = array ();
		$newRow['accRing'] = isset($step['accRing']) ? $step['accRing'] : self::acrDefault;

		$newRow ['person'] = ($r['person'] != 0) ? $r['person'] : $this->docHead['person'];

		$this->searchAccountRows ($step, $r, $newRow);


		$col = utils::param($step, 'col', '');
		$newRow ['money'] = -1234.56;
		$enableZeroMoney = 0;

		switch ($col)
		{
			case	'':
				$newRow ['money'] = round ($r['taxBaseHc'] + $r['taxBaseHcCorr'], 2);
				break;
			case	'invPriceAcc':
				$newRow ['money'] = $r['invPriceAcc'];
				$enableZeroMoney = 1;
				break;
		}

		$newRow ['centre'] = $r['centre'];
		$newRow ['project'] = $r['project'];
		$newRow ['workOrder'] = $r['workOrder'];
		$newRow ['property'] = $r['property'];
		$this->setRowText ($step, $r,$newRow);
		$newRow ['side'] = utils::param($step, 'side', 1);

		$newRow ['cashBookId'] = 0;
		if (isset($step['cashBook']))
		{
			if ($step['cashBook'] == 1)
				$newRow ['cashBookId'] = $r['ndx'];
			if ($step['cashBook'] == 2)
				$newRow ['cashBookId'] = $firstRowId;
		}

		$balanceRequest = utils::param($step, 'balanceRequest', 0);
		$balancePayment = utils::param($step, 'balancePayment', 0);
		$operation = $this->app->cfgItem ('e10.docs.operations.' . $r ['operation'], FALSE);

		if ($balanceRequest || $balancePayment || isset ($newRow['balance']) || ($operation && isset($operation['paymentSymbols'])))
		{
			if ($r['symbol1'] != '')
			{
				$newRow ['symbol1'] = $r['symbol1'];
				$newRow ['symbol2'] = $r['symbol2'];
				$newRow ['symbol3'] = $r['symbol3'];
			}
			else
			{
				$newRow ['symbol1'] = $this->docHead['symbol1'];
				$newRow ['symbol2'] = $this->docHead['symbol2'];
				$newRow ['symbol3'] = '';
			}
			if (!isset ($newRow['balance']))
				$newRow ['balance'] = ($balanceRequest != 0) ? $balanceRequest : $balancePayment;
		}
		else
		{
			$newRow ['symbol1'] = '';
			$newRow ['symbol2'] = '';
			$newRow ['symbol3'] = '';
			$newRow ['balance'] = 0;
		}

		if (!$this->testMoney($step, $newRow))
			return;

		if (isset ($this->docAccounting['dateAccountingSrc']) && $this->docAccounting['dateAccountingSrc'] === 'rowDateDue')
			$newRow['dateAccounting'] = $r['dateDue'];

		$this->incRow($newRow, $enableZeroMoney);
	}

	function setRowText ($step, $docRow, &$accRow)
	{
		if (!isset($step['text']))
		{
			$accRow ['text'] = $docRow['text'];
			return;
		}

		$text = '';
		$recs = [];
		foreach ($step['text'] as $textPart)
		{
			if ($textPart[0] !== '{')
			{
				$text .= $textPart;
				continue;
			}

			$parts = explode ('.', substr($textPart, 1, -1));
			if (count($parts) < 2)
				continue;

			$v = NULL;
			if ($parts[0] === 'row')
			{
				if (count($parts) === 2)
				{
					if (isset($docRow[$parts[1]]))
						$v = $docRow[$parts[1]];
				}
				else
				{
					switch ($parts[1])
					{
						case 'operation':
							if (!isset($recs['row']['operation']))
								$recs['row']['operation'] = $this->app()->cfgItem('e10.docs.operations.' . $docRow['operation'], []);
							break;
						case 'property':
							if (!isset($recs['row']['property']))
								$recs['row']['property'] = $this->app()->loadItem($docRow['property'], 'e10pro.property.property');
							break;
						case 'item':
							if (!isset($recs['row']['item']))
								$recs['row']['item'] = $this->app()->loadItem($docRow['item'], 'e10.witems.items');
							break;
					}

					if (isset($recs['row'][$parts[1]]))
						$v = utils::param($recs['row'][$parts[1]], $parts[2], '');
				}
			}

			if ($v !== NULL)
				$text .= $v;
		}

		if ($text === '')
			$text = $docRow['text'];

		$accRow ['text'] = str::upToLen($text, 120);
	}

	protected function doBalanceRows ($step, $r, $firstRowId)
	{
		if (isset ($step['operation']) && ($r['operation'] != $step['operation']))
			return;
		if (isset ($step['operations']) && !in_array($r['operation'], $step['operations']))
			return;
		if (isset($step['query']) && !$this->testQuery($step['query'], $r))
			return;
		if (isset($step['method']) && !in_array($step['method'], $this->methods))
			return;
		if (!$this->testCash($step))
			return;
		if (!$this->testDocDir($step, $r))
			return;

		if (!isset ($step['balancePayment']))
			return;

		$balanceDefinition = $this->app->cfgItem ('e10.balance.'.$step['balancePayment']);

		// -- search src document ndx
		$q [] = 'SELECT * FROM e10doc_debs_journal WHERE';
		array_push ($q, ' [balance] = %i', $step['balancePayment']);
		array_push ($q, ' AND person = %i', $r['person']);
		//array_push ($q, ' AND fiscalYear = %i', $this->docHead['fiscalYear']);
		array_push ($q, ' AND symbol1 = %s', $r['symbol1']);
		if ($r['symbol2'] != '')
			array_push ($q, ' AND symbol2 = %s', $r['symbol2']);

		if ($balanceDefinition['side'] == 'd')
			array_push ($q, " AND moneyDr != 0");
		else
		if ($balanceDefinition['side'] == 'c')
			array_push ($q, " AND moneyCr != 0");

		$srcDocInfo = $this->db()->query ($q)->fetch();

		//if (!isset($srcDocInfo['document']))
		//	return;

		unset ($q);
		$q [] = 'SELECT * FROM e10doc_debs_journal WHERE';
		array_push ($q, ' [document] = %i', $srcDocInfo['document']);
		array_push ($q, ' AND [balance] != %i', $step['balancePayment']);
		//array_push ($q, ' AND person = %i', $r['person']);
		//array_push ($q, ' AND fiscalYear = %i', $this->docHead['fiscalYear']);
		if ($r['symbol2'] != '')
			array_push ($q, ' AND symbol2 = %s', $r['symbol2']);

		$balanceRows = $this->db()->query ($q);


		if (count ($balanceRows))
		{
			foreach ($balanceRows as $br)
			{
				$newRow = array ();
				$newRow['accRing'] = isset($step['accRing']) ? $step['accRing'] : self::acrDefault;

				$newRow ['money'] = 0.0;
				if (count ($balanceRows) < 2)
					$newRow ['money'] = $r['taxBaseHc'];
				else
				if ($srcDocInfo['money'] != 0.0)
					$newRow ['money'] = round ($br['money']/$srcDocInfo['money']*$r['taxBaseHc'], 2);

				$newRow ['person'] = ($r['person'] != 0) ? $r['person'] : $this->docHead['person'];
				$newRow ['accountId'] = $br ['accountId'];
				$newRow ['centre'] = $r['centre'];
				$newRow ['project'] = $r['project'];
				$newRow ['workOrder'] = $r['workOrder'];
				$newRow ['property'] = $r['property'];
				$newRow ['text'] = $br['text'];
				$newRow ['side'] = utils::param($step, 'side', 1);

				$newRow ['cashBookId'] = 0;
				if (isset($step['cashBook']))
				{
					if ($step['cashBook'] == 1)
						$newRow ['cashBookId'] = $r['ndx'];
					if ($step['cashBook'] == 2)
						$newRow ['cashBookId'] = $firstRowId;
				}

				$balancePayment = utils::param($step, 'balancePayment', 0);
				$newRow ['symbol1'] = $r['symbol1'];
				$newRow ['symbol2'] = $r['symbol2'];
				$newRow ['symbol3'] = $r['symbol3'];
				$newRow ['balance'] = $balancePayment;

				if (!$this->testMoney($step, $newRow))
					continue;

				if (isset ($this->docAccounting['dateAccountingSrc']) && $this->docAccounting['dateAccountingSrc'] === 'rowDateDue')
					$newRow['dateAccounting'] = $r['dateDue'];

				$this->incRow($newRow);

				unset ($newRow);
			}
		}
		else
		{
			$newRow = array ();

			$newRow['accRing'] = isset($step['accRing']) ? $step['accRing'] : self::acrDefault;
			$newRow ['money'] = $r['taxBaseHc'];
			$newRow ['person'] = ($r['person'] != 0) ? $r['person'] : $this->docHead['person'];
			$newRow ['accountId'] = '###';
			$newRow ['centre'] = $r['centre'];
			$newRow ['project'] = $r['project'];
			$newRow ['workOrder'] = $r['workOrder'];
			$newRow ['property'] = $r['property'];
			$newRow ['text'] = $r['text'];
			$newRow ['side'] = utils::param($step, 'side', 1);

			$newRow ['cashBookId'] = 0;
			if (isset($step['cashBook']))
			{
				if ($step['cashBook'] == 1)
					$newRow ['cashBookId'] = $r['ndx'];
				if ($step['cashBook'] == 2)
					$newRow ['cashBookId'] = $firstRowId;
			}

			$balancePayment = utils::param($step, 'balancePayment', 0);
			$newRow ['symbol1'] = $r['symbol1'];
			$newRow ['symbol2'] = $r['symbol2'];
			$newRow ['symbol3'] = $r['symbol3'];
			$newRow ['balance'] = $balancePayment;

			if (isset ($this->docAccounting['dateAccountingSrc']) && $this->docAccounting['dateAccountingSrc'] === 'rowDateDue')
				$newRow['dateAccounting'] = $r['dateDue'];

			$this->incRow($newRow);

			unset ($newRow);
		}
	}

	public function loadSettings ()
	{
		$this->methods = [];
		$fiscalYear = $this->app->cfgItem ('e10doc.acc.periods.'.$this->docHead ['fiscalYear'], FALSE);

		if (!$fiscalYear || $fiscalYear['method'] === 'none')
			return FALSE;

		$this->accMethodId = $fiscalYear['method'];
		$this->methods[] = $fiscalYear['method'];
		$this->methods[] = $fiscalYear['stockAccMethod'];
		$this->methods[] = $fiscalYear['propertyDepsMethod'];

		$stateId = $this->app->cfgItem ('options.core.ownerDomicile', 'cz');
		$settingsFileName = __SHPD_MODULES_DIR__.'install/country-modules/'.$fiscalYear['method'].'/'.$stateId.'/settings/'.'/acc-default.json';
		$this->accOpts = $this->loadCfgFile($settingsFileName);

		return $this->accOpts !== FALSE;
	}

	public function save ()
	{
		if (round($this->sumCredit, 2) !== round($this->sumDebit, 2))
		{
			$this->addMessage("Strany MD/DAL se nerovnají (MD: {$this->sumDebit} x DAL: {$this->sumCredit}).");
		}
		if (count($this->docJournal) === 0)
		{
			$isError = 1;
			if ($this->docHead['docType'] === 'purchase' && $this->docHead['paymentMethod'] == 8)
				$isError = 0; // likvidační protokol
			elseif ($this->docHead['docType'] === 'bank' && $this->cntSrcDocRows === 0)
				$isError = 0; // prázdný bankovní výpis se zůstatkem
			elseif ($this->docHead['docType'] === 'cash' && $this->docHead['initState'] === 1 && $this->cntSrcDocRows === 0)
				$isError = 0; // prázdný pokladní lístek se počátečním stavem

			if ($isError)
				$this->addMessage('Zaúčtování je prázdné.');
		}

		forEach ($this->docJournal as $r)
		{
			if (mb_strlen ($r['text'], 'UTF-8') > 120)
				$r['text'] = mb_substr($r['text'], 0, 120, 'UTF-8');

			$this->db()->query ("INSERT INTO [e10doc_debs_journal]", $r);
		}
	}

	protected function searchAccountHead ($step, $head, &$newRow)
	{
		forEach ($this->accOpts['accounts'] as $a)
		{
			if ($step['cat'] !== $a['cat'])
				continue;
			if (isset($a['query']) && !$this->testQuery($a['query'], $head))
				continue;

			$this->searchAccountId ($a, NULL, $newRow);
			break;
		}
	}

	protected function searchAccountRows ($step, $row, &$newRow)
	{
		// -- accounting operation in document row
		if (isset ($step['forceCat']))
		{
			forEach ($this->accOpts['accounts'] as $a)
			{
				if ($step['forceCat'] !== $a['cat'])
					continue;
				if (isset($a['query']) && !$this->testQuery($a['query'], $row))
					continue;

				$this->searchAccountId ($a, $row, $newRow);
				break;
			}
			return;
		}

		$operation = $this->app->cfgItem ('e10.docs.operations.' . $row ['operation'], FALSE);
		if (isset ($operation['forceAccount']) && utils::param($step, 'ignoreItemAccount', 0) == 0)
		{
			$newRow ['accountId'] = $row ['debsAccountId'];
			return;
		}

		if (isset ($step['balancePayment']))
		{
			$balanceDefinition = $this->app->cfgItem ('e10.balance.'.$step['balancePayment']);

			$q [] = "SELECT * FROM e10doc_debs_journal WHERE";
			array_push ($q, " [balance] = %i", $step['balancePayment']);
			array_push ($q, " AND person = %i", $newRow['person']);
			array_push ($q, " AND fiscalYear = %i", $this->docHead['fiscalYear']);
			array_push ($q, " AND symbol1 = %s", $row['symbol1']);
			if ($row['symbol2'] != '')
				array_push ($q, " AND symbol2 = %s", $row['symbol2']);

			if ($balanceDefinition['side'] == 'd')
				array_push ($q, " AND moneyDr != 0");
			else
			if ($balanceDefinition['side'] == 'c')
				array_push ($q, " AND moneyCr != 0");

			$res = $this->db()->query ($q)->fetch ();
			if ($res)
			{
				$newRow ['accountId'] = $res ['accountId'];
				return;
			}
			$newRow ['accountId'] = $step['cat'].str_repeat('9', 6 - strlen ($step['cat']));
			$this->addMessage('Účet nenalezen');
			return;
		}

		// -- accounting item
		$itemKind = $this->app->cfgItem ('e10.witems.types.'.$row['itemType'].'.kind', FALSE);
		if ($itemKind === 2 && utils::param($step, 'ignoreItemAccount', 0) == 0)
		{
			if ($row ['itemDebsAccountId'] !== '')
			{
				$newRow ['accountId'] = $row ['itemDebsAccountId'];
				$newRow ['balance'] = $row ['itemBalance'];
				return;
			}
			$newRow ['accountId'] = '999999';
			$this->addMessage('Účet nenalezen');
		}

		forEach ($this->accOpts['accounts'] as $a)
		{
			if ($step['cat'] !== $a['cat'])
				continue;
			if (isset($a['query']) && !$this->testQuery($a['query'], $row))
				continue;

			$this->searchAccountId ($a, $row, $newRow);
			break;
		}
	}

	protected function searchAccountVAT ($step, $row, &$newRow)
	{
		if ($step['cat'][0] == '#')
			$newRow ['accountId'] = substr ($step['cat'], 1);
		else
		{
			$taxHomeCountry = E10Utils::docTaxHomeCountryId($this->app(), $this->docHead);
			$taxRegCountry = $this->docTaxRegCfg['taxCountry'] ?? 'cz';

			if ($taxHomeCountry === $taxRegCountry && ($this->docTaxRegCfg['payerKind'] ?? 0) === 0)
				$newRow ['accountId'] = $step['cat'].substr($row['taxCode'], 4);
			else
				$newRow ['accountId'] = $step['cat'].substr($row['taxCode'], 2);

			$this->checkAccountVAT ($newRow ['accountId'], $row['taxCode']);
		}
	}

	protected function checkAccountVAT ($accountId, $taxCodeId)
	{
		$exist = $this->db()->query ('SELECT ndx, id FROM [e10doc_debs_accounts]',
		' WHERE [accGroup] = 0 AND [id] = %s', $accountId,
		' AND accMethod = %s', $this->accMethodId)->fetch();

		if ($exist)
			return;

		$taxCode = E10Utils::taxCodeCfg($this->app, $taxCodeId);
		$docTaxCountryId = E10Utils::docTaxCountryId($this->app(), $this->docHead);
		$taxHomeCountry = E10Utils::docTaxHomeCountryId($this->app(), $this->docHead);
		if ($docTaxCountryId === '')
			$docTaxCountryId = $taxHomeCountry;

		$countryTxt = strtoupper($docTaxCountryId).' ';
		if ($docTaxCountryId === $taxHomeCountry)
			$countryTxt = '';

		$newAccount = [
			'id' => $accountId, 'accountKind' => 5,
			'accGroup' => 0, 'accMethod' => 'debs', 'docState' => 4000, 'docStateMain' => 2,
			'fullName' => 'Daň z přidané hodnoty '.$countryTxt.'('.$taxCode['fullName'].')',
			'shortName' => 'DPH '.$countryTxt.'('.$taxCode['fullName'].')',
		];

		$tableAccounts = $this->app()->table('e10doc.debs.accounts');
		$newAccountNdx = $tableAccounts->dbInsertRec($newAccount);
		$tableAccounts->docsLog ($newAccountNdx);
	}

	protected function searchAccountId ($a, $row, &$newRow)
	{
		if (isset ($a['accountSrc']))
		{
			if ($a['accountSrc'] === 'cashBox')
			{
				$cashBox = $this->app->cfgItem ('e10doc.cashBoxes.'.$this->docHead['cashBox'], FALSE);
				if ($cashBox !== FALSE)
				{
					if (isset ($cashBox['debsAccountId']) && $cashBox['debsAccountId'] != '')
						$newRow ['accountId'] = $cashBox['debsAccountId'];
					else
						$newRow ['accountId'] = $this->searchAccountFromMask ($a['accountMask']);
				}
			}
			else
			if ($a['accountSrc'] === 'warehouse')
			{
				$whOptions = E10Utils::warehouseOptions ($this->app, $this->docHead ['warehouse'], $this->docHead ['fiscalYear']);

				if (isset($a['accountType']) && isset($whOptions[$a['accountType']]) && $whOptions[$a['accountType']] !== '')
					$newRow ['accountId'] = $whOptions[$a['accountType']];

				if (!isset($newRow ['accountId']) || $newRow ['accountId'] === '')
				{
					$newRow ['accountId'] = $a['accountMask'].'999';
					$this->addMessage('Účet nenalezen');
				}
			}
			else
			if ($a['accountSrc'] === 'bankAccount')
			{
				$bankAccount = $this->app->cfgItem ('e10doc.bankAccounts.'.$this->docHead['myBankAccount'], FALSE);
				if ($bankAccount !== FALSE)
				{
					if (isset ($bankAccount['debsAccountId']) && $bankAccount['debsAccountId'] != '')
						$newRow ['accountId'] = $bankAccount['debsAccountId'];
					else
						$newRow ['accountId'] = $this->searchAccountFromMask ($a['accountMask']);
				}
			}
			else
			if ($a['accountSrc'] === 'docKind')
			{
				if ($this->docHead['docKind'] === 0)
				{
					$newRow ['accountId'] = $this->searchAccountFromMask ($a['accountMask']);
				}
				else
				{
					$docKind = $this->app->cfgItem ('e10.docs.kinds.'.$this->docHead['docKind'], FALSE);
					if ($docKind !== FALSE)
					{
						if (isset ($docKind['debsAccountId']) && $docKind['debsAccountId'] != '')
							$newRow ['accountId'] = $docKind['debsAccountId'];
						else
							$newRow ['accountId'] = $this->searchAccountFromMask ($a['accountMask']);
					}
				}
			}
			else
			if ($a['accountSrc'] === 'property')
			{
				if (!isset($row['property']) || $row['property'] === 0)
				{
					$newRow ['accountId'] = $a['accountMask'].'999';
					$this->addMessage('Účet nenalezen');
				}
				else
				{
					$propertyRecData = $this->app()->loadItem($row['property'], 'e10pro.property.property');
					if ($propertyRecData)
					{
						$debsGroup = $this->app()->loadItem($propertyRecData['debsGroup'], 'e10doc.debs.groups');
						if ($debsGroup)
						{
							if (isset($a['accountType']) && isset($debsGroup[$a['accountType']]) && $debsGroup[$a['accountType']] !== '')
								$newRow ['accountId'] = $debsGroup[$a['accountType']];
							elseif ($debsGroup['analytics'] !== '')
								$newRow ['accountId'] = $a['accountMask'].$debsGroup['analytics'];
						}
					}

					if (!isset($newRow ['accountId']) || $newRow ['accountId'] === '')
					{
						$newRow ['accountId'] = $a['accountMask'].'999';
						$this->addMessage('Účet nenalezen');
					}
				}
			}
		}
		else
		if (isset ($a['accountMask']))
		{
			if ($row !== NULL && isset ($row['itemDebsGroup']) && $row['itemDebsGroup'] !== 0)
			{
				$debsGroup = $this->app->cfgItem ('e10debs.groups.'.$row['itemDebsGroup'], FALSE);
				if ($debsGroup !== FALSE)
				{
					$newRow ['accountId'] = $a['accountMask'].$debsGroup['analytics'];
					return;
				}
			}
			$newRow ['accountId'] = $this->searchAccountFromMask ($a['accountMask']);
		}
	}

	protected function searchAccountFromMask ($accountMask)
	{
		$row = $this->db()->query ('SELECT * FROM [e10doc_debs_accounts]',
			' WHERE [accGroup] = 0 AND [id] LIKE %s', $accountMask.'%',
			' AND accMethod = %s', $this->accMethodId,
			' ORDER by id, ndx')->fetch();

		if ($row)
			return $row['id'];

		$this->addMessage('Účet nenalezen');
		return $accountMask.str_repeat('9', 6 - strlen ($accountMask));
	}

	public function setDocument ($recData)
	{
		$this->docHead = $recData;
		$this->docTaxRegCfg = $this->app()->cfgItem ('e10doc.base.taxRegs.'.$this->docHead['vatReg'], NULL);

		if ($recData['fiscalMonth'] == 0 || $recData['fiscalYear'] == 0)
		{
			if ($recData ['initState'])
			{
				$q = "SELECT [ndx] as [fm], [fiscalYear] as [fy] FROM [e10doc_base_fiscalmonths]
						WHERE [calendarYear] = %i AND [fiscalType] = 1";

				$res = $this->db()->query ($q, intval (\E10\df ($recData ['dateAccounting'], '%Y')));
				$r = $res->fetch ();
			}
			else
			{
				$q = "SELECT [ndx] as [fm], [fiscalYear] as [fy] FROM [e10doc_base_fiscalmonths]
						WHERE [start] <= %d AND [end] >= %d AND [fiscalType] = 0";

				$res = $this->db()->query ($q, $recData ['dateAccounting'], $recData ['dateAccounting']);
				$r = $res->fetch ();
			}
			$recData ['fiscalYear'] = intval($r ['fy']);
			$recData ['fiscalMonth'] = intval($r ['fm']);

			if ($recData['fiscalMonth'] == 0 || $recData['fiscalYear'] == 0)
				$this->addMessage("Není nastaveno fiskální období.");
		}

		$this->docFiscalMonth = $this->db()->query ("SELECT * FROM [e10doc_base_fiscalmonths] WHERE [ndx] = %i", $recData['fiscalMonth'])->fetch();
	}

	protected function testCash ($step)
	{
		if (!isset($step['cash']))
			return TRUE;

		$paymentMethod = $this->app->cfgItem ('e10.docs.paymentMethods.' . $this->docHead['paymentMethod'], FALSE);
		if ($paymentMethod === FALSE)
			return FALSE;

		if (isset($paymentMethod['cash']) && $paymentMethod['cash'] === $step['cash'])
			return TRUE;

		return FALSE;
	}

	protected function testDocDir ($step, $rowRecData = NULL)
	{
		if (!isset($step['docDir']))
			return TRUE;

		$tableRows = new \E10Doc\Core\TableRows($this->app);
		$docDir = $tableRows->docDir($rowRecData, $this->docHead);

		return $step['docDir'] === $docDir;
	}

	protected function testMoney ($step, &$newRow)
	{
		if (isset ($step['sign']))
		{
			if ($step['sign'] === '+' && $newRow['money'] < 0.0)
				return FALSE;
			if ($step['sign'] === '-' && $newRow['money'] > 0.0)
				return FALSE;
		}

		if (isset ($step['reverseSign']) && $step['reverseSign'] === 1)
			$newRow['money'] *= -1;

		return TRUE;
	}


	protected function testQuery ($query, $data)
	{
		forEach ($query as $colId => $colValue)
		{
			if ($data[$colId] != $colValue)
				return FALSE;
		}

		return TRUE;
	}

	public function run ()
	{
		if ($this->loadSettings())
			$this->createJournal();
	}
}



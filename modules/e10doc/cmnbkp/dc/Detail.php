<?php

namespace e10doc\cmnbkp\dc;
require_once __SHPD_MODULES_DIR__ . 'e10doc/finance/finance.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/cmnbkp/cmnbkp.php';

use \e10doc\core\libs\E10Utils;
use \e10\base\libs\UtilsBase;


/**
 * Class Detail
 * @package e10doc\cmnbkp\dc
 */
class Detail extends \e10doc\core\dc\Detail
{
	var $item;

	function addContentTaxVatReturn ()
	{
		$debsAccountIdCol = '';
		if ($this->table->app()->model()->table ('e10doc.debs.accounts') !== FALSE)
			$debsAccountIdCol = ', [debsAccountId]';

		$cfgTaxCodes = E10Utils::docTaxCodes($this->app(), $this->item);
		$balances = $this->app()->cfgItem ('e10.balance');

		$q = "SELECT * FROM [e10doc_tax_rows] WHERE [document] = %i";
		$taxRowsTable = $this->table->db()->query($q, $this->item ['ndx']);

		$oldDocument = FALSE;
		if (!count($taxRowsTable))
			$oldDocument = TRUE;

		$taxRows = array();
		foreach ($taxRowsTable as $r)
		{
			$newRow = array();
			$newRow['base'] = $r['base'];
			$newRow['tax'] = $r['tax'];
			$newRow['row'] = $r['row'];
			if (isset($taxRows[$r['kindItem']][$r['taxCode']]))
			{
				$taxRows[$r['kindItem']][$r['taxCode']]['base'] += $newRow['base'];
				$taxRows[$r['kindItem']][$r['taxCode']]['tax'] += $newRow['tax'];
			}
			else
				$taxRows[$r['kindItem']][$r['taxCode']] = $newRow;
		}

		$dataAccounts = array ();
		$dataOthers = [0 => array(), 1 => array()];

		$q = 'SELECT [operation], [person], [item], [symbol1], [symbol2], [dateDue], [itemBalance]'.$debsAccountIdCol.
				', [debit], [credit], [text] FROM [e10doc_core_rows] WHERE document = %i ORDER BY ndx';

		$rows = $this->table->db()->query($q, $this->item ['ndx'])->fetchAll ();
		forEach ($rows as $r)
		{
			$taxCode = $cfgTaxCodes[substr($r['debsAccountId'], 3, 3)];
			switch ($r['operation'])
			{
				case 1099999 :
					if ($this->table->app()->model()->table ('e10doc.debs.accounts') !== FALSE)
					{
						if (!isset ($dataAccounts[$taxCode['dir']] [$taxCode['rowTaxReturn']] [$r['debsAccountId']]))
						{
							if ($taxCode['dir'] == 0)
								$dataAccounts[$taxCode['dir']] [$taxCode['rowTaxReturn']] [$r['debsAccountId']] = ['taxValue' => $r['credit']-$r['debit']];
							else
								if ($taxCode['dir'] == 1)
									$dataAccounts[$taxCode['dir']] [$taxCode['rowTaxReturn']] [$r['debsAccountId']] = ['taxValue' => $r['debit']-$r['credit']];
						}
						else
						{
							if ($taxCode['dir'] == 0)
								$dataAccounts[$taxCode['dir']] [$taxCode['rowTaxReturn']] [$r['debsAccountId'] ['taxValue']] += $r['credit']-$r['debit'];
							else
								if ($taxCode['dir'] == 1)
									$dataAccounts[$taxCode['dir']] [$taxCode['rowTaxReturn']] [$r['debsAccountId'] ['taxValue']] += $r['debit']-$r['credit'];
						}
						$dataAccounts[$taxCode['dir']] [$taxCode['rowTaxReturn']] [$r['debsAccountId']] ['taxName'] = $taxCode['name'];
					}
					break;
				case 1099998 :
					$newRow = $r;
					if (!isset ($itemsTable[$r['item']]))
					{
						$tableItems = new \E10\Witems\TableItems ($this->app());
						$item = array ('ndx' => $r['item']);
						$itemsTable[$r['item']] = $tableItems->loadItem ($item);
					}
					if ($r['itemBalance'])
					{
						if (!isset ($personsTable[$r['person']]))
						{
							$tablePersons = new \E10\Persons\TablePersons ($this->app());
							$person = array ('ndx' => $r['person']);
							$personsTable[$r['person']] = $tablePersons->loadItem ($person);
						}
						$newRow['description'] = $itemsTable[$r['item']]['fullName'].': '.$personsTable[$r['person']]['fullName'];
						$newRow['balance'] = $itemsTable[$r['item']]['useBalance'];
						$dataOthers[1][] = $newRow;
					}
					else
					{
						$newRow['description'] = $itemsTable[$r['item']]['fullName'];
						$dataOthers[0][] = $newRow;
					}
					break;
				default :
					$newRow = $r;
					if (!isset ($personsTable[$r['person']]))
					{
						$tablePersons = new \E10\Persons\TablePersons ($this->app());
						$person = array ('ndx' => $r['person']);
						$personsTable[$r['person']] = $tablePersons->loadItem ($person);
					}
					$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $r['operation'], FALSE);
					$operationName = $operation['title'].': ';
					$newRow['description'] = $operationName.$personsTable[$r['person']]['fullName'];
					$dataOthers[0][] = $newRow;
					break;
			}
		}

		$list = array ();

		$subTotalLabels = [0 => ['description' => 'DPH na vstupu'],
				1 => ['description' => 'DPH na výstupu']];
		foreach ($dataAccounts as $dir => $dirData)
		{
			$baseValueSubtotal = 0.0;
			$taxValueSubtotal = 0.0;
			$badItem = FALSE;
			foreach ($dirData as $line => $lineData)
			{
				foreach ($lineData as $account => $accountData)
				{
					$taxRows['tax-vat-return-sum'][substr($account, 3)]['showed'] = TRUE;
					$baseValue = $taxRows['tax-vat-return-sum'][substr($account, 3)]['base'];
					$item = ['description' => 'Účet: '.$account.' - '.$accountData['taxName'], 'line' => $line, 'taxBase' => $baseValue, 'taxValue' => $accountData['taxValue']];
					if (!$oldDocument)
					{
						if ($taxRows['tax-vat-return-sum'][substr($account, 3)]['tax'] == $accountData['taxValue'])
						{
							$item['state'] = ['icon' => 'system/iconCheck', 'text' => ''];
							$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-play']];
						}
						else
						{
							$item['state'] = ['icon' => 'icon-exclamation-circle', 'text' => ''];
							$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-stop'], 'cellTitles' => ['state' => 'Správná částka DPH má být '.\E10\nf($taxRows['tax-vat-return-sum'][substr($account, 3)]['tax'], 2)]];
							$badItem = TRUE;
						}
					}
					$list [] = $item;
					$baseValueSubtotal += $baseValue;
					$taxValueSubtotal += $accountData['taxValue'];
				}
			}

			$nonAccountingTaxCode = array();
			foreach ($taxRows['tax-vat-return-sum'] as $taxCode => $taxRow)
			{
				if ($taxCode && $dir == $cfgTaxCodes[$taxCode]['dir'] && $taxRow['showed'] !== TRUE)
					$nonAccountingTaxCode[$taxCode] =  ['row' => $taxRow['row']];
			}
			asort ($nonAccountingTaxCode);
			foreach ($nonAccountingTaxCode as $taxCode => $r)
			{
				$taxBaseValue = $taxRows['tax-vat-return-sum'][$taxCode]['base'];
				$taxValue = $taxRows['tax-vat-return-sum'][$taxCode]['tax'];
				$item = ['description' => 'Neúčtuje se - '.$cfgTaxCodes[$taxCode]['name'], 'line' => $r['row'], 'taxBase' => $taxBaseValue];
				$item['state'] = ['icon' => 'system/iconCheck', 'text' => ''];
				$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-play', 'description' => 'e10-off', 'line' => 'e10-off', 'taxBase' => 'e10-off']];
				if ($taxValue !== 0.0)
				{
					$item['taxValue'] = $taxValue;
					$item['state']['icon'] = 'icon-exclamation-circle';
					$item['_options']['cellClasses']['state'] = 'e10-icon e10-row-stop';
					$item['_options']['cellClasses']['taxValue'] = 'e10-off';
					$item['_options']['cellTitles']['state'] = 'Tato sazba DPH by měla být obsažena v dokladu';
					$badItem = TRUE;
					$taxValueSubtotal += $taxValue;
				}
				$list[] = $item;
				$baseValueSubtotal += $taxBaseValue;
			}

			$stateClasses = 'e10-icon e10-row-play';
			$iconState = 'system/iconCheck';
			if ($badItem)
			{
				$stateClasses = 'e10-icon e10-row-stop';
				$iconState = 'icon-exclamation-circle';
			}
			$list[] = [
				'description' => $subTotalLabels[$dir]['description'] ?? '!!!',
				'taxBase' => $baseValueSubtotal, 'taxValue' => $taxValueSubtotal,
				'state' => ['icon' => $iconState, 'text' => ''],
				'_options' => ['class' => 'subtotal', 'afterSeparator' => 'separator', 'cellClasses' => ['state' => $stateClasses], 'colSpan' => ['description' => 2]]
			];
		}

		$cnt = 0;
		foreach ($dataOthers as $balance => $balanceRow)
		{
			foreach ($balanceRow as $row)
			{
				$item = $row;

				if ($oldDocument)
					$options['colSpan'] = ['description' => 2];
				else
					$options['colSpan'] = ['description' => 3];

				if ($balance > 0)
				{
					$options['class'] = 'sum';
					if ($balances[$row['balance']]['side'] == 'c')
						$item['taxValue'] = $row['credit']-$row['debit'];
					else
						$item['taxValue'] = $row['debit']-$row['credit'];
					$item['state'] = ['icon' => 'system/iconCheck', 'text' => ''];
					$options['cellClasses'] = ['state' => 'e10-icon e10-row-play'];
				}
				else
				{
					$item['taxValue'] = $row['debit']-$row['credit'];
					$item['state'] = ['icon' => 'system/iconCheck', 'text' => ''];
					$options['cellClasses'] = ['state' => 'e10-icon e10-row-play'];
				}
				$item['_options'] = $options;
				$list[] = $item;
				$cnt++;
			}
		}

		$h = ['#' => '#', 'description' => 'Popis', 'line' => ' Řádek přiznání', 'taxBase' => ' Základ DPH', 'taxValue' => ' Částka DPH',
				'state' => ['icon' => 'icon-bolt', 'text' => ''], '_options' => ['cellClasses' => ['state' => 'e10-icon']]];

		if ($oldDocument)
		{
			unset ($h['taxBase']);
			unset ($h['state']);
		}

		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky dokladu'],
				'header' => $h, 'table' => $list, 'params' => ['disableZeros' => 1]]);

		$checkRows = [
				1 => ['description' => 'Doklady přiznání DPH', 'details' => ['enforceOpen' => TRUE, 'colLabel' => ['c3' => 'Datum', 'c4' => 'Měna', 'c5' => 'Přiznání'], 'colSpan' => ['c1' => 2]]],
				2 => ['description' => 'Sumární přehled a doklady přiznání DPH', 'details' => ['colLabel' => ['c3' => 'Přehled', 'c5' => 'Přiznání'], 'colSpan' => ['c1' => 2, 'c3' => 2]]],
				3 => ['description' => 'Zaúčtování DPH', 'details' => ['colLabel' => ['c5' => 'Zůstatek'], 'colSpan' => ['c1' => 4]]],
				4 => ['description' => 'Uzavření období DPH', 'details' => ['iconNameOK' => 'icon-lock', 'iconNameWarning' => 'icon-unlock']]];

		$taxPeriod = $this->app()->loadItem ($this->item['taxPeriod'], 'e10doc.base.taxperiods');
		$fiscalMonth = $this->app()->loadItem ($this->item['fiscalMonth'], 'e10doc.base.fiscalmonths');
		$list = array ();
		$listState = -1;
		foreach ($checkRows as $key => $r)
		{
			if (!$this->checkTaxPeriod ($key, $r, $taxPeriod, $fiscalMonth, $list, $listState))
				break;
		}
		$h = ['c1' => '', 'c2' => '', 'c3' => ' ', 'c4' => '', 'c5' => ' ', 'state' => ''];
//		$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
//							'title' => ['icon' => 'icon-flag', 'text' => 'Stav DPH v období '.$taxPeriod['fullName']],
//							'header' => $h, 'table' => $list, 'params' => ['hideHeader' => 1]]);
	}

	function checkTaxPeriod ($key, $checkRow, $taxPeriod, $fiscalMonth, &$list, &$listState)
	{
		$detailList = array ();
		$stateRes = $this->checkTaxPeriod_Details($key, $taxPeriod, $fiscalMonth, $detailList);

		$item = array ();
		$item['c1'] = $checkRow['description'];

		if ($stateRes)
		{
			$stateClass = 'e10-icon e10-row-play';
			if (isset ($checkRow['details']['iconNameOK']))
				$stateIconName = $checkRow['details']['iconNameOK'];
			else
				$stateIconName = 'system/iconCheck';
		}
		else
		{
			$stateClass = 'e10-icon e10-row-stop';
			if (isset ($checkRow['details']['iconNameWarning']))
				$stateIconName = $checkRow['details']['iconNameWarning'];
			else
				$stateIconName = 'icon-exclamation-circle';
		}
		$item['state'] = ['icon' => $stateIconName, 'text' => ''];
		$item['_options'] = ['class' => 'subtotal', 'cellClasses' => ['state' => $stateClass]];

		if ($stateRes && $checkRow['details']['enforceOpen'] !== TRUE)
			$item['_options']['colSpan'] = ['c1' => 5];
		else
		{
			if (isset ($checkRow['details']['colLabel']))
				foreach ($checkRow['details']['colLabel'] as $k1 => $c1)
					$item[$k1] = $c1;
			if (isset ($checkRow['details']['colSpan']))
				foreach ($checkRow['details']['colSpan'] as $k2 => $c2)
					$item['_options']['colSpan'][$k2] = $c2;
		}
		if ($listState != -1 && ($listState == 0 || $stateRes == FALSE))
			$item['_options']['beforeSeparator'] = 'separator';
		$list[] = $item;

		if ($stateRes)
			$listState = 1;
		else
			$listState = 0;

		if (!$stateRes || $checkRow['details']['enforceOpen'] === TRUE)
		{
			foreach ($detailList as $dl)
			{
				$list[] = $dl;
			}
			$listState = 0;
		}

		return TRUE;
	}

	function checkTaxPeriod_Details ($key, $taxPeriod, $fiscalMonth, &$list)
	{
		$stateRes = TRUE;

		switch ($key)
		{
			case 1:
			{
				$docsTypes = $this->app()->cfgItem ('e10.docs.types');
				$currencies = $this->app()->cfgItem ('e10.base.currencies');
				$q = "SELECT [ndx], [docNumber], [docState], [docType], [activity], [dateAccounting],[currency], [credit]".
						" FROM [e10doc_core_heads] WHERE taxPeriod = %i AND activity = 'taxVatReturn' ORDER BY dateAccounting, ndx";
				$docs = $this->table->db()->query($q, $taxPeriod['ndx'])->fetchAll ();
				forEach ($docs as $d)
				{
					$item = array ();
					$item['c1'] = ['text'=> $d['docNumber'], 'icon' => $docsTypes[$d['docType']]['icon'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $d['ndx']];
					$item['c2'] = $docsTypes['cmnbkp']['activities'][$d['activity']]['name'];
					$docStates = $this->table->documentStates ($d);
					$docStateClass = $this->table->getDocumentStateInfo ($docStates, $d, 'styleClass');
					$item['c3'] = \E10\df($d['dateAccounting'], '%D');
					$item['c4'] = $currencies[$d['currency']]['shortcut'];
					$item['c5'] = \E10\nf($d['credit'], 2);

					if ($d['docState'] == 4000)
					{
						$item['state'] = ['icon' => 'system/iconCheck', 'text' => ''];
						$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-play', 'c1' => $docStateClass]];
					}
					else
					{
						$item['state'] = ['icon' => 'icon-exclamation-circle', 'text' => ''];
						$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-stop', 'c1' => $docStateClass]];
						$stateRes = FALSE;
					}
					$list[] = $item;
				}

				if(count ($docs) == 0)
				{
					$item = array ();
					$item['c1'] = 'Žádný doklad přiznání DPH!';
					$item['state'] = ['icon' => 'icon-exclamation-circle', 'text' => ''];
					$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-stop'], 'colSpan' => ['c1' => 5]];
					$list[] = $item;
					$stateRes = FALSE;
				}
				break;
			}
			case 2:
			{
				// -- report VAT
				$rvr = new \E10Doc\Finance\reportVAT ($this->app());
				$rvr->taxPeriod = $taxPeriod['ndx'];
				$reportVAT =  $rvr->createData_Summary();

				foreach ($reportVAT as $rv)
				{
					if (!isset( $rv['_options']['class']) || $rv['_options']['class'] != 'subtotal' && $rv['_options']['class'] != 'sumtotal')
					{
						$item = array ();
						$item['c1'] = $rv['title'];
						$item['c3'] = \E10\nf($rv['tax'], 2);
						$item['c5'] = \E10\nf(0.0, 2);
						if ($rv['tax'] == 0.0)
						{
							$item['state'] = ['icon' => 'system/iconCheck', 'text' => ''];
							$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-play'], 'colSpan' => ['c1' => 2, 'c3' => 2]];
						}
						else
						{
							$item['state'] = ['icon' => 'icon-exclamation-circle', 'text' => ''];
							$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-stop'], 'colSpan' => ['c1' => 2, 'c3' => 2]];
							$stateRes = FALSE;
						}
						$list[] = $item;
					}
				}
				break;
			}
			case 3:
			{
				if ($this->app()->model()->table ('e10doc.debs.accounts') !== FALSE)
				{
					// -- account names
					$qac = "SELECT id, shortName FROM e10doc_debs_accounts WHERE docStateMain < 3";
					$accounts = $this->table->db()->query($qac);
					$accNames = $accounts->fetchPairs ('id', 'shortName');

					// -- general ledger
					$glr = new \e10doc\debs\libs\reports\GeneralLedger($this->app());
					$glr->fiscalYear = $fiscalMonth['fiscalYear'];
					$glr->fiscalPeriod = $fiscalMonth['ndx'];
					$generalLedger =  $glr->createContent_Data();

					foreach ($generalLedger as $gl)
					{
						$item = array ();
						if (substr ($gl['accountId'], 0, 3) == '343' && $gl['accGroup'] !== TRUE)
						{
							$item['c1'] = 'Účet: '.$gl['accountId'].' - '.$accNames[$gl['accountId']];
							$item['c5'] = \E10\nf($gl['endState'], 2);
							if ($gl['endState'] != 0.0)
							{
								$item['state'] = ['icon' => 'icon-exclamation-circle', 'text' => ''];
								$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-stop'], 'colSpan' => ['c1' => 4]];
								$stateRes = FALSE;
							}
							else
							{
								$item['state'] = ['icon' => 'system/iconCheck', 'text' => ''];
								$item['_options'] = ['cellClasses' => ['state' => 'e10-icon e10-row-play'], 'colSpan' => ['c1' => 4]];
							}
							$list[] = $item;
						}
					}
				}
				break;
			}
			case 4:
			{
				if ($taxPeriod ['docStateMain'] < 4)
					$stateRes = FALSE;
				break;
			}
		}
		return $stateRes;
	}

	function addContentCmnBkpRows ($params)
	{
		$debsAccountIdCol = FALSE;
		if ($this->table->app()->model()->table ('e10doc.debs.accounts') !== FALSE)
			$debsAccountIdCol = ' [debsAccountId],';

		$list = array ();
		$totalCr = 0.0;
		$totalDr = 0.0;
		$totalRowCnt = 0;
		$usedSubtotal = FALSE;

		foreach ($params['queries'] as $key => $query)
		{
			$rowCnt = 0;
			$subTotalCr = 0.0;
			$subTotalDr = 0.0;

			if ($query !== FALSE)
				$usedSubtotal = TRUE;

			$q = array ();
			array_push($q, 'SELECT [operation], [person], [item], [symbol1], [symbol2], [dateDue], [itemBalance],');
			if ($debsAccountIdCol !== FALSE)
				array_push($q, $debsAccountIdCol);
			array_push($q, ' [debit], [credit], [text] FROM [e10doc_core_rows] AS [rows]');
			array_push($q, ' WHERE [rows].document = %i', $this->item ['ndx']);
			if ($query !== FALSE && $key != '!')
				array_push($q, ' AND '.$query['column'].' = %s', $key);
			if ($query !== FALSE && $key == '!')
			{
				foreach ($params['queries'] as $k2 => $q2)
				{
					if ($k2 != '!')
						array_push($q, ' AND '.$q2['column'].' != %s', $k2);
				}
			}
			array_push($q, ' ORDER BY rowOrder, ndx');

			$rows = $this->table->db()->query($q)->fetchAll ();
			forEach ($rows as $r)
			{
				$newRow = $r->toArray();

				switch ($r['operation'])
				{
					case 1099999 :
						if ($this->table->app()->model()->table ('e10doc.debs.accounts') !== FALSE)
						{
							if (!isset ($sccountsTable[$r['debsAccountId']]))
							{
								$qAccount = 'SELECT [fullName] FROM [e10doc_debs_accounts] WHERE [id] = %s AND [docState] != 9800 LIMIT 1';
								$resAccount = $this->table->db()->query($qAccount, $r['debsAccountId'])->fetchAll ();

								foreach ($resAccount as $a)
								{
									$accountsTable[$r['debsAccountId']] = $a;
								}
							}
							$newRow['description'] = 'Účet: '.$r['debsAccountId'].' - '.$accountsTable[$r['debsAccountId']]['fullName'];
						}
						break;
					case 1099998 :
						if (!isset ($itemsTable[$r['item']]))
						{
							$tableItems = new \E10\Witems\TableItems ($this->app());
							$item = array ('ndx' => $r['item']);
							$itemsTable[$r['item']] = $tableItems->loadItem ($item);
						}
						if ($r['itemBalance'])
						{
							if (!isset ($personsTable[$r['person']]))
							{
								$tablePersons = new \E10\Persons\TablePersons ($this->app());
								$person = array ('ndx' => $r['person']);
								$personsTable[$r['person']] = $tablePersons->loadItem ($person);
							}
							$newRow['description'] = $itemsTable[$r['item']]['fullName'].': '.$personsTable[$r['person']]['fullName'];
						}
						else
							$newRow['description'] = $itemsTable[$r['item']]['fullName'];
						break;
					default :
						if (!isset ($personsTable[$r['person']]))
						{
							$tablePersons = new \E10\Persons\TablePersons ($this->app());
							$person = array ('ndx' => $r['person']);
							$personsTable[$r['person']] = $tablePersons->loadItem ($person);
						}
						$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $r['operation'], FALSE);
						$operationName = $operation['title'].': ';
						$newRow['description'] = $operationName.$personsTable[$r['person']]['fullName'];
						break;
				}

				$totalCr += $r['credit'];
				$totalDr += $r['debit'];
				$subTotalCr += $r['credit'];
				$subTotalDr += $r['debit'];

				$list[] = $newRow;

				if ($subTotalCr == $subTotalDr && ($rowCnt < count($rows)-1) && $query === FALSE || ($rowCnt == count($rows)-1 && $usedSubtotal))
				{

					$itemSubTotal = array ('credit' => $subTotalCr, 'debit' => $subTotalDr, '_options' => array ('class' => 'subtotal', 'afterSeparator' => 'separator', 'colSpan' => $params['colSpan']));
					if ($query !== FALSE)
						$itemSubTotal['text'] = $query['name'];
					$list[] = $itemSubTotal;
					$subTotalCr = 0.0;
					$subTotalDr = 0.0;
					$usedSubtotal = TRUE;
				}

				$rowCnt++;
				$totalRowCnt++;
			}
		}

		if ($totalRowCnt)
			$list[] = array ('credit' => $totalCr, 'debit' => $totalDr, '_options' => ['disableZeros' => FALSE, 'class' => 'sum', 'colSpan' => $params['colSpan']]);

		$h = array ('#' => '#', 'description' => 'Popis', 'text' => 'Text řádku');
		if (isset($params['show']))
		{
			foreach ($params['show'] as $key => $show)
				$h[$key] = $show;
		}
		$h['debit'] = ' MD';
		$h['credit'] = ' DAL';

		if (isset($params['hide']))
		{
			foreach ($params['hide'] as $key => $hide)
				unset ($h[$key]);
		}

		$this->addContent ('body', array ('pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky dokladu'],
				'header' => $h, 'table' => $list, 'params' => array ('disableZeros' => 1)));
	}


	function addContentPropertyAccRows ($params)
	{
		$useProperty = ($this->app->model()->table ('e10pro.property.property') !== FALSE);

		$list = array ();
		$q = array ();
		array_push($q, 'SELECT r.priceItem AS priceItem, r.property AS property, p.propertyId AS propertyId, p.fullName AS fullName');
		array_push($q, ' FROM [e10doc_core_rows] AS r LEFT JOIN [e10pro_property_property] AS p ON (r.property = p.ndx)');
		array_push($q, ' WHERE r.document = %i', $this->item ['ndx']);
		array_push($q, ' ORDER BY r.rowOrder, r.ndx');

		$totalPrice = 0;
		$totalRowCnt = 0;
		$rows = $this->table->db()->query($q)->fetchAll ();
		forEach ($rows as $r)
		{
			$newItem = [];
			$newItem['priceItem'] = $r['priceItem'];
			if ($useProperty && isset($r['property']) && $r['property'])
			{
				$newItem ['property'] = [
					'text' => $r['propertyId'], 'title' => $r['fullName'],'docAction' => 'edit',
					'table' => 'e10pro.property.property', 'pk' => $r['property']
				];
			}
			else
				$newItem['property'] = $r['propertyId'];
			$newItem['fullName'] = $r['fullName'];
			$totalPrice += $r['priceItem'];
			$list[] = $newItem;
			$totalRowCnt++;
		}

		if ($totalRowCnt)
			$list[] = array ('priceItem' => $totalPrice, '_options' => ['disableZeros' => FALSE, 'class' => 'sum']);

		$h = array ('#' => '#', 'property' => 'Majetek', 'fullName' => 'Název', 'priceItem' => ' Částka');
		$docTypes = $this->app->cfgItem ('e10.docs.types');
		$this->addContent ('body', array ('pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky dokladu ('.$docTypes['cmnbkp']['activities'][$params]['name'].')'],
			'header' => $h, 'table' => $list, 'params' => array ('disableZeros' => 1)));
	}

	function addContentImport ()
	{
		$testCmnBkpDocImports = $this->app()->cfgItem ('options.experimental.testCmnBkpDocImports', 0);
		if (!$testCmnBkpDocImports)
			return;

		$allAttachments = UtilsBase::loadAllRecAttachments($this->app(), 'e10doc.core.heads', $this->recData['ndx']);
		$files = [];
		foreach ($allAttachments as $a)
		{
			$srcFullFileName = __APP_DIR__.'/att/'. $a['path'].$a['filename'];
			$files[] = $srcFullFileName;
		}

		$ie = new \e10doc\cmnbkp\libs\imports\ImportHelper($this->app());

		/** @var \e10doc\cmnbkp\libs\imports\cardTrans\ImportCardTrans */
		$importEngine = $ie->createImportFromAttachments($files);

		if ($importEngine)
		{
			$importEngine->setDocument($this->recData['ndx']);

			$btns = [];
      $btns [] = [
        'type' => 'action', 'action' => 'addwizard',
        'text' => 'Přegenerovat doklad', 'data-class' => 'e10doc.cmnbkp.libs.imports.WizardRunImport',
        'icon' => 'cmnbkpRegenerateOpenedPeriod',
        'class' => 'pull-right'
      ];

			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table',
				'type' => 'line', 'line' => $btns,
				'paneTitle' => ['icon' => 'icon-file-text', 'text' => $importEngine->title(), 'class' => 'h2 block']
			]);
		}
	}

	public function createContentBody ()
	{
		$this->linkedDocuments();

		$this->item = $this->recData;

		if ($this->item['credit'] != $this->item['debit'])
		{
			$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'text', 'title' => ['icon' => 'icon-file-text', 'text' => 'Strany MD a DAL se nerovnají!']]);
		}

		$this->addContentImport();

		switch ($this->item['activity'])
		{
			case 'balSetOff': // zápočet
				$this->addContentCmnBkpRows (array ('queries' => ['1090001' => ['column' => 'operation', 'name' => 'Pohledávky'],
					'1090002' => ['column' => 'operation', 'name' => 'Závazky'],
					'!' => ['column' => 'operation', 'name' => 'Ostatní']],
					'hide' => ['description' => TRUE],
					'show' => ['symbol1' => 'VS', 'symbol2' => 'SS', 'dateDue' => 'Splatnost'],
					'colSpan' => ['text' => 4]));
				break;

			//case 'taxVatReturn': // DPH
			//	$this->addContentTaxVatReturn();
			//	break;

			case 'prpActivate': // Zařazení majetku
			case 'prpDiscard': // Vyřazení majetku
			case 'prpDeps': // Odpisy majetku
				$this->addContentPropertyAccRows($this->item['activity']);
				break;

			default: // obecný účetní doklad
				$this->addContentCmnBkpRows (array ('queries' => ['0' => FALSE], 'colSpan' => ['description' => 2]));
		}
		$this->attachments();
	}

}



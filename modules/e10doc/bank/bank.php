<?php

namespace E10Doc\Bank;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm;
use \E10\Application, E10\Wizard, \E10\utils, \E10\DataModel;
use E10Doc\Core\e10utils;
use \E10Doc\Core\ViewDetailHead;
use \Shipard\Utils\Str;




/**
 * createImportObject
 *
 */

function createImportObject ($app, $textData)
{
//	$textData = iconv('CP1250', "UTF-8", $textData);

	$ffn = __SHPD_MODULES_DIR__ . 'e10doc/bank/ebanking/formats.json';
	$formatsTxt = file_get_contents($ffn);

	$formats = json_decode($formatsTxt, TRUE);

	$id = '';
	forEach ($formats as $f)
	{
		if (isset ($f['checkRegExp']))
		{
			if (preg_match ($f['checkRegExp'], $textData) === 1)
			{
				$id = $f['id'];
				break;
			}
		}
		if (isset ($f['checkRegExp2']))
		{
			if (preg_match ($f['checkRegExp2'], $textData) === 1)
			{
				$id = $f['id'];
				break;
			}
		}
	}

	if ($id === '')
	{
		error_log ("unrecognized ebanking format...");
		return FALSE;
	}

	$path = str_replace('.', '/', $id);
	$namespace = 'E10Doc\\Bank\\Import\\' . str_replace(array('.', '-'), '_', $id);
	$fullClassName = $namespace . '\\Import';
	require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/ebanking/' . $path . '/import.php';
	if (class_exists ($fullClassName))
	{
		$o = new $fullClassName ($app);
		if (isset ($f['srcCharset']))
		{
			$td = iconv($f['srcCharset'], 'UTF-8', $textData);
			$o->setTextData ($td);
		}
		else
			$o->setTextData ($textData);
		return $o;
	}

	return FALSE;
}

/*
 * ebankingImportDoc
 */

class ebankingImportDoc extends \E10\Utility
{
	public $docHead = array ();
	public $docRows = array ();

	protected $textData = '';
	protected $importHead = array ();
	protected $importRow = array ();
	protected $importedRows = array ();
	protected $myBankAccount = 0;
	protected $myBankAccountCurrency = '';
	protected $replaceDocumentNdx = 0;
	protected $lastRowMemo = '';
	protected $inboxNdx = 0;

	public function clear ()
	{
		$this->docHead = array ();
		$this->docRows = array ();
		$this->importHead = array ();
		$this->importRow = array ();
		$this->importedRows = array ();
		$this->myBankAccount = 0;
		$this->lastRowMemo = '';
	}

	public function checkBankAccount ()
	{
		if (isset ($this->importHead['bankAccount']))
		{
			$q1 = "SELECT * FROM [e10doc_base_bankaccounts] WHERE [bankAccount] = %s OR [ebankingId] = %s OR [iban] = %s";
			$ba = $this->db()->query ($q1, $this->importHead['bankAccount'], $this->importHead['bankAccount'], $this->importHead['bankAccount'])->fetch();
			if ($ba)
			{
				$this->myBankAccount = $ba['ndx'];
				$this->myBankAccountCurrency = $ba['currency'];
				return;
			}
			else
			{
				$this->addMessage ("Nenašlo se vlastní bankovní číslo '{$this->importHead['bankAccount']}'. Toto číslo musí být uvedeno v některém vlastním bankovním spojení jako Číslo účtu nebo ID pro ebanking.");
			}
		}
	}

	public function checkRowPerson (&$row)
	{
		if (!isset ($row['bankAccount']))
			return;
		if ($row['bankAccount'] == '' && $row['symbol1'] == '' && $row['symbol2'] == '')
			return;

		// -- check in balance via symbols
		if ($row['credit'] > 0.0)
		{
			$money = $row['credit'];
			$balanceType = 1000;
		}
		else
		{
			$money = $row['debit'];
			$balanceType = 2000;
		}

		$q2 [] = "SELECT pairId, person, sum(amount) as amount, sum(request) as request, sum(payment) as payment, (sum(request) - sum(payment)) as bilance FROM e10doc_balance_journal WHERE 1 ";
		$q2 [] = "AND [type] = $balanceType ";
		if (isset ($row['symbol1']) && $row['symbol1'] != '')
			array_push ($q2, " AND [symbol1] = %s ", $row['symbol1']);
		$q2 [] = "GROUP BY pairId ";

		$q2 [] = "HAVING bilance > 0 ";

		$b2 = $this->db()->query ($q2)->fetchAll();
		if ($b2)
		{
			if (count($b2) === 1)
				$row['person'] = $b2[0]['person'];
			else
			{
				// -- 1. equal amount
				$bestDelta = $money;
				$bestPerson = 0;
				forEach ($b2 as $bal)
				{
					if ($bal['bilance'] == $money)
					{
						$row['person'] = $bal['person'];
						break;
					}
					$delta = abs ($bal['bilance'] - $money);
					if ($delta < $bestDelta)
					{
						$bestDelta = $delta;
						$bestPerson =  $bal['person'];
					}
				}

				// -- 2. best amount
				if ($row['person'] === 0 && $bestPerson !== 0)
					$row['person'] = $bestPerson;
			}
		} // if ($b2)

		if ($row['person'] === 0 && $row['bankAccount'] !== '')
		{
			// -- bank account in persons properties
			$q1 = "SELECT recid FROM [e10_base_properties] where [group] = 'payments' AND property = 'bankaccount' AND valueString = %s";
			$persons = $this->db()->query ($q1, $row['bankAccount'])->fetchAll();
			if ($persons)
			{
				if (count($persons) === 1)
					$row['person'] = $persons[0]['recid'];
			}
		}
	} // checkRowPerson

	public function checkRowValues (&$row)
	{
		if (!isset ($row['bankAccount']))
			return;
		if ($row['bankAccount'] == '' && $row['symbol1'] == '' && $row['symbol2'] == '')
			return;

		// -- check in balance via symbols
		if ($row['credit'] > 0.0)
		{
			$money = $row['credit'];
			$balanceType = 1000;
		}
		else
		{
			$money = $row['debit'];
			$balanceType = 2000;
		}

		$q2 [] = "SELECT pairId, person, sum(amount) as amount, sum(request) as request, sum(payment) as payment, (sum(request) - sum(payment)) as bilance FROM e10doc_balance_journal WHERE 1 ";
		$q2 [] = "AND [type] = $balanceType ";

		$srcSymbol1 = '';
		if (isset ($row['symbol1']))
			$srcSymbol1 = $row['symbol1'];
		array_push ($q2, " AND [symbol1] = %s ", $srcSymbol1);

		$q2 [] = "GROUP BY pairId ";
		$q2 [] = "HAVING bilance > 0 ";

		$b2 = $this->db()->query ($q2)->fetchAll();
		if ($b2)
		{
			if (count($b2) === 1)
				$row['person'] = $b2[0]['person'];
			else
			{
				// -- 1. equal amount
				$bestDelta = $money;
				$bestPerson = 0;
				forEach ($b2 as $bal)
				{
					if ($bal['bilance'] == $money)
					{
						$row['person'] = $bal['person'];
						break;
					}
					$delta = abs ($bal['bilance'] - $money);
					if ($delta < $bestDelta)
					{
						$bestDelta = $delta;
						$bestPerson =  $bal['person'];
					}
				}

				// -- 2. best amount
				if ($row['person'] === 0 && $bestPerson !== 0)
					$row['person'] = $bestPerson;
			}
		} // if ($b2)

		if ($row['person'] === 0 && $row['bankAccount'] !== '')
		{
			// -- bank account in persons properties
			$q1 = "SELECT recid FROM [e10_base_properties] where [group] = 'payments' AND property = 'bankaccount' AND valueString = %s";
			$persons = $this->db()->query ($q1, $row['bankAccount'])->fetchAll();
			if ($persons)
			{
				if (count($persons) === 1)
					$row['person'] = $persons[0]['recid'];
			}
		}
	}

	public function createDocHead ()
	{
		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);

		if ($this->replaceDocumentNdx === 0)
		{
			$this->docHead = array ('docType' => 'bank');
			$tableDocs->checkNewRec ($this->docHead);
			$this->docHead ['docType'] = 'bank';
		}

		$this->docHead ['myBankAccount'] = $this->myBankAccount;

		if ($this->myBankAccountCurrency !== '')
			$this->docHead ['currency'] = $this->myBankAccountCurrency;

		$this->docHead ['datePeriodBegin'] = $this->importHead ['datePeriodBegin'];
		$this->docHead ['datePeriodEnd'] = $this->importHead ['datePeriodEnd'];

		//$this->docHead ['person'] = $contract['person'];
		//$this->docHead ['title'] = $contract['title'];

		$this->docHead ['dateIssue'] = $this->importHead ['datePeriodEnd'];
		$this->docHead ['dateAccounting'] = $this->importHead ['datePeriodEnd'];
		$this->docHead ['dateDue'] = $this->importHead ['datePeriodEnd'];

		if (isset($this->importHead ['docOrderNumber']))
			$this->docHead ['docOrderNumber'] = $this->importHead ['docOrderNumber'];
		$this->docHead ['initBalance'] = $this->importHead ['initBalance'];

		//$this->docHead ['currency'] = $contract['currency'];
		//$this->docHead ['author'] = intval(Application::cfgItem ('options.e10doc-sale.author', 0));
		//$this->docHead ['centre'] = $contract['centre'];

		//$this->docRows = array ();


		if (!isset($this->docHead ['docOrderNumber']) || $this->docHead ['docOrderNumber'] === 0)
		{
			$lastDocOrderNumber = 0;
			$year = $this->importHead ['datePeriodBegin']->format('Y');
			$docOrderRec = $this->db()->query (
				"SELECT docOrderNumber FROM e10doc_core_heads WHERE docType = %s AND myBankAccount = %i AND YEAR(dateAccounting) = %i AND docState != 9800 ORDER BY docOrderNumber DESC",
				'bank', $this->docHead ['myBankAccount'], $year)->fetch();

			if ($docOrderRec)
				$lastDocOrderNumber = $docOrderRec['docOrderNumber'];

			$this->docHead ['docOrderNumber'] = $lastDocOrderNumber + 1;
		}
	}

	public function createDocRow ($row)
	{
		$useDocRowsSettings = $this->app()->cfgItem ('options.experimental.testDocRowsSettings', 0);

		$tableHeads = new \E10Doc\Core\TableHeads ($this->app);
		$tableRows = new \E10Doc\Core\TableRows ($this->app);
		$r = array ();
		$tableRows->checkNewRec($r);

		//$r['item'] = $row['item'];

		if (isset($row['memo']))
			$r['text'] = Str::upToLen(implode ('|', $row['memo']), 220);

		//$r['quantity'] = $row['quantity'];
		//$r['unit'] = $row['unit'];

		$r['debit'] = 0.0;
		$r['credit'] = 0.0;
		if ($row['money'] < 0.0)
			$r['debit'] = abs($row['money']);
		else
			$r['credit'] = $row['money'];

		$r['bankAccount'] = isset ($row['bankAccount']) ? $row['bankAccount'] : '';
		$r['symbol1'] = isset ($row['symbol1']) ? $row['symbol1'] : '';
		$r['symbol2'] = isset ($row['symbol2']) ? $row['symbol2'] : '';
		$r['symbol3'] = isset ($row['symbol3']) ? $row['symbol3'] : '';

		$r['dateDue'] = $row['dateDue'];

		$r['priceItem'] = 0;
		$r['quantity'] = 1;
		$r['person'] = 0;

		if (isset ($row['exchangeRate']))
			$r['exchangeRate'] = $row['exchangeRate'];
		else
		{
			$r['exchangeRate'] = 1;
			if ($this->docHead['currency'] !== $this->docHead['homeCurrency'])
			{
				$er = e10utils::exchangeRate($this->app(), $r['dateDue'], $this->docHead['homeCurrency'], $this->docHead['currency']);
				if ($er)
					$r['exchangeRate'] = $er;
			}
		}

		if (isset ($row['person']) && $row['person'] !== 0)
			$r['person'] = $row['person'];
		else
		{
			if ($useDocRowsSettings)
				$this->checkRowValues($r);
			else
				$this->checkRowPerson($r);
		}

		if ($useDocRowsSettings)
		{
			$rowsSettings = new \e10doc\helpers\RowsSettings($this->app());
			$rowsSettings->run($r, $this->docHead);
		}

		$this->docRows[] = $r;
	}

	public function import () {}

	public function parseDate ($value)
	{
		return date_create_from_format ('d.m.Y', $value);
	}

	public function parseNumber ($value)
	{
		$s = str_replace(' ', '', $value);
		$s = str_replace('.', '', $s);
		$s = str_replace(',', '.', $s);

		return floatval($s);
	}

	public function run ()
	{
		$this->import ();
		$this->saveDoc ();
	}

	function saveDoc ()
	{
		$this->checkBankAccount ();
		if ($this->myBankAccount === 0)
			return;

		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$tableRows = new \E10Doc\Core\TableRows ($this->app);

		if ($this->replaceDocumentNdx !== 0)
			$this->docHead = $tableDocs->loadItem ($this->replaceDocumentNdx);

		$this->createDocHead ();
		forEach ($this->importedRows as $r)
			$this->createDocRow ($r);

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
	}

	function setHeadInfo ($id, $value)
	{
		$this->importHead [$id] = $value;
	}

	function setRowInfo ($id, $value, $blankValue = FALSE)
	{
		if ($id === 'memo')
		{
			$v = trim ($value);
			if ($v !== '' && $v != $this->lastRowMemo)
				$this->importRow [$id][] = $v;
			$this->lastRowMemo = $v;
		}
		else
		{
			if ($blankValue !== FALSE && $value === $blankValue)
				$this->importRow [$id] = '';
			else
				$this->importRow [$id] = $value;
		}
	}

	public function substr ($str, $from, $len = 0)
	{
		if ($len === 0)
			return trim (mb_substr ($str, $from, mb_strlen ($str, 'utf-8'), 'utf-8'));
		return trim (mb_substr ($str, $from, $len, 'utf-8'));
	}

	function appendRow ()
	{
		$this->importedRows [] = $this->importRow;
		$this->importRow = array ();
		$this->lastRowMemo = '';
	}

	function setTextData ($textData)
	{
		$this->textData = $textData;
	}

	public function setReplaceDocumentNdx ($ndx)
	{
		$this->replaceDocumentNdx = $ndx;
		return TRUE;
	}

	public function setInboxNdx ($ndx)
	{
		$this->inboxNdx = $ndx;
	}
}



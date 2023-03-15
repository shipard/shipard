<?php

namespace e10doc\cmnbkp\libs;
use e10\utils;
use \e10doc\core\libs\E10Utils;

/**
 * Class InitStatesBalanceEngine
 * @package e10doc\cmnbkp\libs
 */
class InitStatesBalanceEngine extends \E10\Utility
{
	var $closeDocs = FALSE;
	var $resetClosedDocs = FALSE;
	var $fiscalYear;
	var $fiscalYearCfg;

	/** @var \e10doc\core\TableHeads */
	var $tableDocs;
	/** @var \e10doc\core\TableRows */
	var $tableRows;
	var $balances;
	var $currencies;
	var $itemsForBal = [];

	function createDocHead($balance, $currency, $debsAccountId)
	{
		$linkId = "OPENBAL;{$this->fiscalYear};{$balance['id']};$currency;$debsAccountId";
		$docDate = $this->fiscalYearCfg['begin'];

		$q = 'SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s AND [linkId] = %s';
		$existedDocs = $this->db()->query($q, 'cmnbkp', $linkId)->fetch();
		if ($existedDocs && ($existedDocs['docState'] === 4000 && $this->resetClosedDocs === FALSE))
		{
			return FALSE;
		}

		if ($existedDocs && ($existedDocs['docState'] === 1000 || $existedDocs['docState'] === 1200 || $existedDocs['docState'] === 4000 || $existedDocs['docState'] === 8000))
		{ // new/confirmed/edited
			$docH = $existedDocs->toArray();
		}
		else
		{
			$docH = array();
			$docH ['docType'] = 'cmnbkp';
			$this->tableDocs->checkNewRec($docH);
		}

		$title = $balance['name'] . ' ' . $this->currencies[$currency]['shortcut'] . ' ' . $this->fiscalYearCfg['fullName'];
		if ($debsAccountId !== '')
		{
			$accRow = $this->db()->query("SELECT * FROM [e10doc_debs_accounts] WHERE [accGroup] = 0 AND [id] = %s ORDER by id, ndx LIMIT 1",
				$debsAccountId)->fetch();
			if (isset ($accRow['shortName']))
				$title .= ' / ' . $accRow['shortName'];
		}

		// dbcounter id
		$dbCounter = $this->dbCounter();
		if (!$dbCounter)
		{
			error_log("ERROR - InitStatesBalanceEngine: dbCounter not found.");
			return FALSE;
		}

		// docKind
		$docKinds = $this->app->cfgItem('e10.docs.kinds', FALSE);
		$dk = utils::searchArray($docKinds, 'activity', 'ocpBalInSt');

		$docH ['dateAccounting'] = $docDate;
		$docH ['dateIssue'] = $docDate;
		$docH ['person'] = $docH ['owner'];
		$docH ['title'] = $title;
		$docH ['taxCalc'] = 0;
		$docH ['currency'] = $currency;
		$docH ['dbCounter'] = $dbCounter;
		$docH ['initState'] = 1;
		$docH ['linkId'] = $linkId;
		$docH ['docKind'] = $dk['ndx'];
		$docH ['activity'] = 'ocpBalInSt';

		$homeCurrency = utils::homeCurrency($this->app(), $docH['dateAccounting']);
		if ($docH ['currency'] !== $homeCurrency)
		{
			$er = E10Utils::exchangeRate($this->app(), $docH['dateAccounting'], $homeCurrency, $docH ['currency']);
			$docH ['exchangeRate'] = $er;
		}

		return $docH;
	}

	function createDocRows($head, $balance, $balanceRows)
	{
		$newRows = array();
		$closeRowCredit = 0.0;
		$closeRowDebit = 0.0;
		foreach ($balanceRows as $r)
		{
			$newRow = array();
			if (!isset($r['debsAccountId']))
				$r['debsAccountId'] = '';

			$newRow ['item'] = $this->itemForBalance($balance, $r['debsAccountId']);
			$newRow ['text'] = $r['docTitle'];
			$newRow ['quantity'] = 1;
			$newRow ['priceItem'] = $r['rest'];
			$newRow ['person'] = $r['personNdx'];

			$newRow ['credit'] = 0.0;
			$newRow ['debit'] = 0.0;

			if ($balance['side'] == 'c')
			{
				$newRow ['credit'] = $r['rest'];
				$closeRowDebit += $r['rest'];
			}
			else
			{
				$newRow ['debit'] = $r['rest'];
				$closeRowCredit += $r['rest'];
			}

			$newRow ['dateDue'] = $r['date'];
			$newRow ['symbol1'] = $r['s1'];
			$newRow ['symbol2'] = $r['s2'];
			$newRow ['symbol3'] = $r['s3'];
			$newRow ['bankAccount'] = $r['bankAccount'];

			$newRows[] = $newRow;
		}

		$closeRow = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask('701'), 'text' => $head['title'],
			'credit' => $closeRowCredit, 'debit' => $closeRowDebit];
		$newRows[] = $closeRow;

		return $newRows;
	}

	protected function searchAccountFromMask($accountMask)
	{
		$row = $this->db()->query("SELECT * FROM [e10doc_debs_accounts] WHERE [accGroup] = 0 AND [id] LIKE %s ORDER by id, ndx", $accountMask . '%')->fetch();
		if ($row)
			return $row['id'];

		return $accountMask . str_repeat('9', 6 - strlen($accountMask));
	}

	protected function itemForBalance($balance, $debsAccountId)
	{
		$key = $balance['id'] . '-' . $debsAccountId;
		if (isset ($this->itemsForBal[$key]))
			return $this->itemsForBal[$key];

		$this->itemsForBal[$key] = 0;

		$q = 'SELECT * FROM [e10_witems_items] WHERE [useBalance] = %i AND [debsAccountId] = %s';
		$row = $this->app->db()->query($q, $balance['id'], $debsAccountId)->fetch();
		if ($row['ndx'])
			$this->itemsForBal[$key] = $row['ndx'];

		return $this->itemsForBal[$key];
	}

	function prepareBalanceRows($balance, $fiscalYear)
	{
		$q [] = 'SELECT heads.docNumber, heads.title as docTitle, persons.fullName, persons.ndx as personNdx, saldo.*, ';
		array_push($q, ' saldo.symbol1 as symbol1, saldo.symbol2 as symbol2, saldo.symbol3 as symbol3, saldo.currency as currency, saldo.bankAccount as bankAccount');
		array_push($q, ' FROM e10doc_balance_journal as saldo');
		array_push($q, '	LEFT JOIN e10_persons_persons as persons ON saldo.person = persons.ndx');
		array_push($q, '	LEFT JOIN e10doc_core_heads as heads ON saldo.docHead = heads.ndx');
		array_push($q, ' WHERE');

		array_push($q, ' saldo.[fiscalYear] = %i AND ', $fiscalYear);
		array_push($q, ' EXISTS (',
			'	SELECT pairId, sum(amount) as amount, sum(request) as request, sum(payment) as payment',
			'	FROM [e10doc_balance_journal] as q',
			'	WHERE q.[type] = %i', $balance['id'], ' AND q.pairId = saldo.pairId AND q.[fiscalYear] = %i ', $fiscalYear,
			' GROUP BY q.pairId ',
			' HAVING [request] != payment',
			')');
		array_push($q, ' ORDER BY persons.fullName, saldo.[date] DESC, pairId');

		$rows = $this->app->db()->query($q);
		$data = array();
		foreach ($rows as $r)
		{
			$c = $r['currency'];
			$pid = $r['pairId'];
			if ($r['side'] == 0)
			{ // request
				if (isset($data[$c][$pid]))
				{
					$data[$c][$pid]['request'] += $r['request'];
					$data[$c][$pid]['docNumber'] = $r['docNumber'];
					$data[$c][$pid]['docNdx'] = $r['docHead'];
					$data[$c][$pid]['docTitle'] = $r['docTitle'];
					$data[$c][$pid]['personNdx'] = $r['personNdx'];
					$data[$c][$pid]['date'] = $r['date'];
					$data[$c][$pid]['curr'] = $r['currency'];
					if (!isset ($data[$c][$pid]['debsAccountId']))
						$data[$c][$pid]['debsAccountId'] = $r['debsAccountId'];
				}
				else
				{
					$item = array(
						'docNumber' => $r['docNumber'], 'docNdx' => $r['docHead'], 'docTitle' => $r['docTitle'], 'personNdx' => $r['personNdx'],
						'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'],
						'payment' => $r['payment'],
						'debsAccountId' => $r['debsAccountId'],
						's1' => $r['symbol1'],
						's2' => $r['symbol2'],
						's3' => $r['symbol3'],
						'bankAccount' => $r['bankAccount']);
					$item['curr'] = $r['currency'];

					$data[$c][$pid] = $item;
				}
			}
			else
			{
				if (isset($data[$c][$pid]))
				{
					$data[$c][$pid]['payment'] += $r['payment'];
				}
				else
				{
					$item = array(
						'docNumber' => $r['docNumber'], 'docNdx' => $r['docHead'], 'docTitle' => $r['docTitle'], 'personNdx' => $r['personNdx'],
						'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'],
						'payment' => $r['payment'],
						's1' => $r['symbol1'],
						's2' => $r['symbol2'],
						's3' => $r['symbol3'],
						'bankAccount' => $r['bankAccount']);
					$item['curr'] = $r['currency'];
					$data[$c][$pid] = $item;
				}
			}
			$data[$c][$pid]['rest'] = $data[$c][$pid]['request'] - $data[$c][$pid]['payment'];
		}

		$data2 = array();
		foreach ($data as $currId => $currRows)
		{
			foreach ($currRows as $pid => $row)
			{
				$debsAccountId = '';
				if (isset ($row['debsAccountId']))
					$debsAccountId = $row['debsAccountId'];

				$data2[$currId][$debsAccountId][$pid] = $row;
			}
		}

		return $data2;
	}

	function setParams($fiscalYear)
	{
		$this->fiscalYear = intval($fiscalYear);
		$this->fiscalYearCfg = $this->app->cfgItem('e10doc.acc.periods.' . $fiscalYear);
	}

	function run()
	{
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);

		$this->balances = $this->app->cfgItem('e10.balance');
		$this->currencies = $this->app->cfgItem('e10.base.currencies');


		$this->app->db->begin();

		foreach ($this->balances as $balance)
		{
			$balanceRows = $this->prepareBalanceRows($balance, $this->fiscalYearCfg['prevNdx']);
			foreach ($balanceRows as $currId => $currRows)
			{
				foreach ($currRows as $debsAccountId => $accountRows)
				{
					$docHead = $this->createDocHead($balance, $currId, $debsAccountId);
					if ($docHead === FALSE)
						continue;
					$docRows = $this->createDocRows($docHead, $balance, $accountRows);
					if (count($docRows) !== 0)
						$this->save($docHead, $docRows);
				}
			}
		}

		$this->app->db->commit();
	}

	protected function save($head, $rows)
	{
		if (!isset ($head['ndx']))
		{
			$docNdx = $this->tableDocs->dbInsertRec($head);
		}
		else
		{
			$docNdx = $head['ndx'];
			$this->db()->query('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
			$this->tableDocs->dbUpdateRec($head);
		}

		$f = $this->tableDocs->getTableForm('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec($f->recData);

		foreach ($rows as $row)
		{
			$row['document'] = $docNdx;
			$this->tableRows->dbInsertRec($row, $f->recData);
		}

		if ($this->closeDocs)
		{
			$f->recData ['docState'] = 4000;
			$f->recData ['docStateMain'] = 2;
			$this->tableDocs->checkDocumentState($f->recData);
		}
		$f->checkAfterSave();
		$this->tableDocs->dbUpdateRec($f->recData);
		$this->tableDocs->checkAfterSave2($f->recData);
		$this->tableDocs->docsLog($f->recData['ndx']);
	}

	protected function dbCounter()
	{
		$dbCounter = $this->db()->query('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s', 'cmnbkp',
			' AND [docKeyId] = %s', '9', ' AND docState != %i', 9800)->fetch();
		if (!isset ($dbCounter['ndx']))
			return 0;

		return $dbCounter['ndx'];
	}
}

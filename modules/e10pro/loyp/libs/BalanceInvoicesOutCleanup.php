<?php

namespace e10pro\loyp\libs;
use \Shipard\Utils\Utils;


/**
 * class BalanceInvoicesOutCleanup
 */
class BalanceInvoicesOutCleanup extends \Shipard\Base\Utility
{
  var $docNdx = 0;
  var $docRecData = NULL;

  var $balances = NULL;
  /** @var \e10doc\core\TableHeads */
	var $tableDocs;
	/** @var \e10doc\core\TableRows */
	var $tableRows;

  var $itemsForBal = [];


  public function setDocNumber($docNumber)
  {
    $docRecData = $this->db()->query('SELECT * FROM [e10doc_core_heads] WHERE docNumber = %s', $docNumber)->fetch();
    if (!$docRecData)
    {
      $this->err('Doklad s číslem '.$docNumber.' neexistuje.');
      return;
    }

    $this->docNdx = $docRecData['ndx'];
    $this->docRecData = $docRecData->toArray();

    $this->balances = $this->app->cfgItem('e10.balance');
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);

    $this->tableDocs->documentOpen($this->docNdx);
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
      ' AND q.debsAccountId = %s', '315500',
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
					$item = [
						'docNumber' => $r['docNumber'], 'docNdx' => $r['docHead'], 'docTitle' => $r['docTitle'], 'personNdx' => $r['personNdx'],
						'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'],
						'payment' => $r['payment'],
						'debsAccountId' => $r['debsAccountId'],
						's1' => $r['symbol1'],
						's2' => $r['symbol2'],
						's3' => $r['symbol3'],
						'bankAccount' => $r['bankAccount']
          ];
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
					$item = [
						'docNumber' => $r['docNumber'], 'docNdx' => $r['docHead'], 'docTitle' => $r['docTitle'], 'personNdx' => $r['personNdx'],
						'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'],
						'payment' => $r['payment'],
						's1' => $r['symbol1'],
						's2' => $r['symbol2'],
						's3' => $r['symbol3'],
						'bankAccount' => $r['bankAccount']
          ];
					$item['curr'] = $r['currency'];
					$data[$c][$pid] = $item;
				}
			}
			$data[$c][$pid]['rest'] = $data[$c][$pid]['request'] - $data[$c][$pid]['payment'];
		}

		$data2 = [];
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

    //print_r($data2);

		return $data2;
	}

  function createDocRows($head, $balance, $balanceRows)
	{
		$newRows = [];
		$closeRowCredit = 0.0;
		$closeRowDebit = 0.0;
		foreach ($balanceRows as $r)
		{
			$newRow = [];
			if (!isset($r['debsAccountId']))
				$r['debsAccountId'] = '';

			$newRow ['item'] = $this->itemForBalance($balance, $r['debsAccountId']);
			$newRow ['text'] = $r['docTitle'];
			$newRow ['quantity'] = 1;
			$newRow ['priceItem'] = $r['rest'];
			$newRow ['person'] = $r['personNdx'];

			$newRow ['credit'] = 0.0;
			$newRow ['debit'] = 0.0;

			if ($balance['side'] == 'd')
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


		$closeRow = [
      'operation' => 1099999, 'debsAccountId' => '395801',
      'text' => $head['title'],
			'credit' => $closeRowCredit,
      'debit' => $closeRowDebit
    ];
		$newRows[] = $closeRow;

		return $newRows;
	}

	protected function save($head, $rows)
	{
    $docNdx = $head['ndx'];
    $this->db()->query('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
    $this->tableDocs->dbUpdateRec($head);

		$f = $this->tableDocs->getTableForm('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec($f->recData);

		foreach ($rows as $row)
		{
			$row['document'] = $docNdx;
			$this->tableRows->dbInsertRec($row, $f->recData);
		}


		if (1 /*$this->closeDocs*/)
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


  public function run()
  {
    $balance = $this->balances['1000'];
    $balanceRows = $this->prepareBalanceRows($balance, $this->docRecData['fiscalYear']);
    foreach ($balanceRows as $currId => $currRows)
    {
      foreach ($currRows as $debsAccountId => $accountRows)
      {
        $docRows = $this->createDocRows($this->docRecData, $balance, $accountRows);
        $this->save($this->docRecData, $docRows);
      }
    }

  }
}

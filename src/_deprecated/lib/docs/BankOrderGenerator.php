<?php

namespace lib\docs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \E10\utils, \E10\Utility;
use E10Doc\Core\e10utils;


/**
 * Class BankOrderGenerator
 * @package lib\docs
 */
class BankOrderGenerator extends Utility
{
	var $tableDocs;
	var $tableRows;
	var $docTypes;
	var $bankAccounts;

	public $bankOrderNdx = 0;

	protected $params;
	protected $balance;
	protected $itemsForPayment = [];
	protected $paymentInfo = [];
	protected $fiscalYear;

	function createDocHead ($myBankAccountNdx, $currency)
	{
		$docH = ['docType' => 'bankorder', 'myBankAccount' => $myBankAccountNdx];
		$this->tableDocs->checkNewRec ($docH);

		$title = (isset($this->params['title'])) ? $this->params['title'] : '';
		$docH ['title'] 		= $title;
		$docH ['dateDue'] 	= utils::today();
		$docH ['person']		= $this->bankAccounts[$myBankAccountNdx]['bank'];

		return $docH;
	}

	function createDocRows ($head, $rows)
	{
		$newRows = [];
		forEach ($rows as $r)
		{
			$newRow = [];

			$title = '';
			if ($title === '')
				$title = $this->docTypes[$r['docType']]['shortName'].' '.$r['docNumber'];

			$newRow ['text'] = $title;
			$newRow ['quantity'] = 1;
			$newRow ['priceItem'] = $r['rest'];
			$newRow ['person'] = $r['personNdx'];

			$newRow ['credit'] = 0.0;
			$newRow ['debit'] = 0.0;

			$newRow ['symbol1'] = $r['s1'];
			$newRow ['symbol2'] = $r['s2'];
			$newRow ['symbol3'] = $r['s3'];
			$newRow ['bankAccount'] = $r['bankAccount'];
			$newRow ['operation'] = 1030101;

			$newRows[] = $newRow;
		}

		return $newRows;
	}


	function prepareBalanceRows ()
	{
		$q [] = 'SELECT heads.docNumber, heads.title as docTitle, heads.docType as docType, persons.fullName, persons.ndx as personNdx, heads.myBankAccount as myBankAccount, saldo.*, ';
		array_push ($q, ' saldo.symbol1 as symbol1, saldo.symbol2 as symbol2, saldo.symbol3 as symbol3,');
		array_push ($q, ' saldo.currency as currency, saldo.bankAccount as bankAccount, heads.paymentMethod as paymentMethod');
		array_push ($q, ' FROM e10doc_balance_journal as saldo');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON saldo.person = persons.ndx');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON saldo.docHead = heads.ndx');
		array_push ($q, ' WHERE');

		array_push ($q, ' saldo.[fiscalYear] = %i', $this->fiscalYear);

		if (isset ($this->params['docType']))
			array_push ($q, ' AND heads.[doctype] = %s', $this->params['docType']);

		array_push ($q, ' AND EXISTS (',
			'	SELECT pairId, sum(amount) as amount, sum(request) as request, sum(payment) as payment',
			'	FROM [e10doc_balance_journal] as q',
			'	WHERE q.[type] = %i', $this->balance, ' AND q.pairId = saldo.pairId AND q.[fiscalYear] = %i ', $this->fiscalYear,
			' GROUP BY q.pairId ',
			' HAVING [request] > payment',
			')');
		array_push ($q, ' ORDER BY persons.fullName, saldo.[date] DESC, pairId');

		$rows = $this->app->db()->query ($q);
		$data = array ();
		forEach ($rows as $r)
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
					$item = array (
						'docNumber' => $r['docNumber'], 'docType' => $r['docType'], 'docNdx' => $r['docHead'], 'docTitle'=> $r['docTitle'],
						'paymentMethod' => $r['paymentMethod'],
						'personNdx' => $r['personNdx'], 'fullName' => $r['fullName'],
						'date' => $r['date'],
						'request' => $r['request'], 'payment' => $r['payment'],
						'debsAccountId' => $r['debsAccountId'],
						's1' => $r['symbol1'], 's2' => $r['symbol2'], 's3' => $r['symbol3'],
						'bankAccount' => $r['bankAccount'], 'myBankAccount' => $r['myBankAccount']);
					$item['curr'] = $r['currency'];

					$data[$c][$pid] = $item;
				}
			}
			else
			{ // payment
				if (isset($data[$c][$pid]))
				{
					$data[$c][$pid]['payment'] += $r['payment'];
				}
				else
				{
					$item = array (
						'docNumber' => $r['docNumber'], 'docType' => $r['docType'], 'docNdx'=> $r['docHead'], 'docTitle'=> $r['docTitle'],
						'paymentMethod' => $r['paymentMethod'],
						'personNdx' => $r['personNdx'], 'fullName' => $r['fullName'],
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


		forEach ($data as $currId => $currRows)
		{
			forEach ($currRows as $pid => $row)
			{
				$paymentId = $this->balance.'-'.$row['personNdx'].'-'.$row['s1'].'-'.$row['s2'].'-'.$row['s3'];
				if (isset ($this->paymentInfo[$paymentId]))
					continue;

				if ($row['bankAccount'] == '')
					continue;
				if ($row['paymentMethod'] != 0)
					continue;

				$myBankAccount = 1;
				if (isset ($row['myBankAccount']) && $row['myBankAccount'])
					$myBankAccount = $row['myBankAccount'];

				$this->itemsForPayment[$currId][$myBankAccount][$pid] = $row;
			}
		}
	}

	protected function save ($head, $rows)
	{
		$this->app->db()->begin();

		$docNdx = $this->tableDocs->dbInsertRec ($head);
		$f = $this->tableDocs->getTableForm ('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec ($f->recData);

		forEach ($rows as $row)
		{
			$row['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($row, $f->recData);
		}

		$f->recData ['docState'] = 1200;
		$f->recData ['docStateMain'] = 1;
		$this->tableDocs->checkDocumentState ($f->recData);
		$f->checkAfterSave();
		$this->tableDocs->dbUpdateRec ($f->recData);
		$this->tableDocs->checkAfterSave2 ($f->recData);

		$this->app->db()->commit();

		$this->bankOrderNdx = $docNdx;
	}

	public function generateAll ()
	{
		foreach ($this->itemsForPayment as $currId => $currItems)
		{
			foreach ($currItems as $myBankAccountNdx => $myBankAccountItems)
			{
				$this->generateOne($myBankAccountNdx, $currId, $myBankAccountItems);
			}
		}
	}

	public function loadPaymentInfo ()
	{
		$q[] = 'SELECT [rows].*, heads.dateDue as dateDueHead FROM [e10doc_core_rows] AS [rows]';
		array_push($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND heads.docType = %s', 'bankorder');
		array_push($q, ' AND heads.docStateMain <= %i', 2);
		array_push($q, ' AND heads.docState != %i', 4100);

		array_push($q, ' ORDER BY heads.dateAccounting DESC, [rows].ndx');
		array_push($q, ' LIMIT 1000');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$paymentId = $this->balance.'-'.$r['person'].'-'.$r['symbol1'].'-'.$r['symbol2'].'-'.$r['symbol3'];
			$paymentInfo = ['amount' => $r['priceAll'], 'currency' => $r['currency']];

			if (!utils::dateIsBlank($r['dateDue']))
				$paymentInfo['dateDue'] = $r['dateDue'];
			else
				$paymentInfo['dateDue'] = $r['dateDueHead'];

			$this->paymentInfo[$paymentId][] = $paymentInfo;
		}
	}


	public function generateOne($myBankAccountNdx, $currId, $items)
	{
		$docHead = $this->createDocHead ($myBankAccountNdx, $currId);
		if ($docHead === FALSE)
			return;
		$docRows = $this->createDocRows($docHead, $items);
		if (count($docRows) !== 0)
			$this->save ($docHead, $docRows);
	}

	public function setParams ($params)
	{
		$this->params = $params;
	}

	public function init ()
	{
		$this->balance = 2000;
		$this->fiscalYear = e10utils::todayFiscalYear($this->app);

		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);

		$this->docTypes = $this->app->cfgItem ('e10.docs.types');
		$this->bankAccounts = $this->app->cfgItem ('e10doc.bankAccounts', []);
	}

	public function run ()
	{
		$this->init();
		$this->loadPaymentInfo();
		$this->prepareBalanceRows();
		$this->generateAll();
	}
}


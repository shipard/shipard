<?php

namespace E10Doc\Balance;
require_once __SHPD_MODULES_DIR__ . 'e10doc/balance/balance.php';


use \E10\utils, e10doc\core\libs\E10Utils;
use \E10\HeaderData;
use \E10\DbTable;

class TableJournal extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.balance.journal", "e10doc_balance_journal", "Saldokonto");
	}

	public function doIt (&$recData)
	{
		$br = new Balance ($this->app());
		$br->setDocument($recData);

		$br->clearDocumentRows();

		if ($recData ['docState'] != 4000)
			return;

		$br->createBalanceJournal();
	}
} // class TableJournal


/*
 * ViewJournalAll
 *
 */

class ViewJournalAll extends \E10\TableViewGrid
{
	var $centres;
	var $docTypes;
	var $currencies;
	var $balances;

	public function init ()
	{
		$this->centres = $this->table->app()->cfgItem ('e10doc.centres');
		$this->docTypes = $this->table->app()->cfgItem ('e10.docs.types');
		$this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->balances = $this->table->app()->cfgItem ('e10.balance');

		$this->topParams = new \e10doc\core\libs\GlobalParams ($this->table->app());
		$this->addParamBalance ();
		$this->topParams->addParam ('fiscalYear', 'queryFiscalYear', ['colWidth' => 3, 'flags' => ['enableAll']]);
		$this->topParams->addParam ('float', 'queryAmount', ['title' => 'Částka', 'colWidth' => 2]);
		$this->topParams->addParam ('float', 'queryAmountDiff', ['title' => '+/-', 'colWidth' => 1]);
		$this->topParams->addParam ('switch', 'querySide', ['colWidth' => 2, 'switch' => ['-' => 'Obě strany', 'req' => 'Předpisy', 'pay' => 'Úhrady']]);
		$this->topParams->addParam ('string', 'queryAccount', ['title' => 'Účet', 'colWidth' => 2]);

		parent::init();

		$g = array (
			'bal' => 'Saldo',
			'date' => 'Datum',
			'dt' => 'DD',
			'docNumber' => 'Doklad',
			'debsAccountId' => 'Účet',
			'symbol1' => 'VS', 'symbol2' => 'SS',
			'pn' => 'Osoba',
			'request' => ' Předpis',
			'payment' => ' Úhrada',
			'currency' => 'Měna',
		);

		$this->setGrid ($g);

		$this->setInfo('title', 'Saldokontní deník');
		$this->setInfo('icon', 'icon-star-o');
	}

	protected function addParamBalance ()
	{
		$balances = [];
		foreach ($this->balances as $balId => $bal)
			$balances[$balId] = $bal['shortName'];
		$balances['0'] = 'Všecha salda';
		$this->topParams->addParam('switch', 'queryBalance', array ('switch' => $balances));
	}

	public function createToolbar (){return array();}

	public function renderRow ($item)
	{
		$listItem ['bal'] = $this->balances[$item['type']]['shortcut'];
		//$listItem ['dateAccounting'] = $item['dateAccounting'];
		$listItem ['date'] = $item['date'];
		$listItem ['debsAccountId'] = $item['debsAccountId'];
		$listItem ['symbol1'] = $item['symbol1'];
		$listItem ['symbol2'] = $item['symbol2'];
		$listItem ['pn'] = $item['personName'];

		if ($item['request'] != 0.0)
			$listItem ['request'] = $item['request'];
		if ($item['payment'] != 0.0)
			$listItem ['payment'] = $item['payment'];

		$listItem ['dt'] = $this->docTypes[$item['docType']]['shortcut'];
		$listItem ['docNumber'] = array ('text'=> $item['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $item['docHead']);

		$listItem ['currency'] = $this->currencies[$item['currency']]['shortcut'];


		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT journal.*, heads.docNumber as docNumber, heads.docType as docType, persons.fullName AS personName FROM [e10doc_balance_journal] AS journal';
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON journal.person = persons.ndx');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON journal.docHead = heads.ndx');
		array_push ($q, ' WHERE 1');

		// -- balance
		if ($this->topParamsValues['queryBalance']['value'] != 0)
		{
			$this->setInfo('param', 'Saldo', $this->topParamsValues['queryBalance']['activeTitle']);
			array_push ($q, ' AND [type] = %i', $this->topParamsValues['queryBalance']['value']);
		}

		// -- fiscalYear
		if ($this->topParamsValues['queryFiscalYear']['value'] != 0)
		{
			$this->setInfo('param', 'Rok', $this->topParamsValues['queryFiscalYear']['activeTitle']);
			array_push ($q, ' AND journal.[fiscalYear] = %i', $this->topParamsValues['queryFiscalYear']['value']);
		}

		// -- amount
		if ($this->topParamsValues['queryAmount']['value'] === '' && $this->topParamsValues['querySide']['value'] !== '-')
		{
			$column = '';
			if ($this->topParamsValues['querySide']['value'] === 'req')
				$column = 'request';
			else if ($this->topParamsValues['querySide']['value'] === 'pay')
				$column = 'payment';

			if ($column !== '')
				array_push ($q, " AND $column != 0");
		}
		else
		{
			$column = 'amount';
			if ($this->topParamsValues['querySide']['value'] === 'req')
				$column = 'request';
			else if ($this->topParamsValues['querySide']['value'] === 'pay')
				$column = 'payment';
			$paramValueName = E10Utils::amountQuery ($q, $column, $this->topParamsValues['queryAmount']['value'], $this->topParamsValues['queryAmountDiff']['value']);
			if ($paramValueName !== '')
				$this->setInfo('param', 'Částka', $paramValueName);
		}

		// -- side info
		if ($this->topParamsValues['querySide']['value'] !== '-')
			$this->setInfo('param', 'Strana', $this->topParamsValues['querySide']['activeTitle']);

		// -- account
		if (isset ($this->topParamsValues['queryAccount']['value']) && $this->topParamsValues['queryAccount']['value'] != '')
		{
			array_push ($q, " AND debsAccountId LIKE %s", $this->topParamsValues['queryAccount']['value'].'%');
			$this->setInfo('param', 'Účet', $this->topParamsValues['queryAccount']['value']);
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND (");
			array_push ($q, " journal.[symbol1] LIKE %s", $fts.'%');
			array_push ($q, " OR journal.[symbol2] LIKE %s", $fts.'%');
			array_push ($q, " OR [docNumber] LIKE %s", $fts.'%');
			array_push ($q, " OR persons.[fullName] LIKE %s", '%'.$fts.'%');
			array_push ($q, ")");

			$this->setInfo('param', 'Hledaný text', $fts);
		}

		array_push ($q, ' ORDER BY [date]' . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

} // class ViewJournalAll


/**
 * Class ViewJournalCombo
 * @package E10Doc\Balance
 */
class ViewJournalCombo extends \E10\TableView
{
	var $docTypes;
	var $currencies;
	var $balances;
	var $operation = 0;
	var $currency = '';
	var $forceHc = FALSE;

	var $balanceId = 0;
	var $pairIds = [];
	var $texts = [];
	var $paymentInfo = [];

	public function init ()
	{
		$this->docTypes = $this->table->app()->cfgItem ('e10.docs.types');
		$this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->balances = $this->table->app()->cfgItem ('e10.balance');

		parent::init();
	}

	public function createToolbar (){return array();}

	public function renderRow ($item)
	{
		$this->pairIds[] = $item['pairId'];

		$listItem ['pk'] = 0;
		$listItem ['pairId'] = $item['pairId'];
		$listItem ['data-cc']['symbol1'] = $item['symbol1'];
		$listItem ['data-cc']['symbol2'] = $item['symbol2'];
		$listItem ['data-cc']['symbol3'] = $item['symbol3'];
		$listItem ['data-cc']['person'] = $item['person'];
		$listItem ['data-cc']['bankRequestCurrency'] = $item['currency'];

		$listItem ['t1'] = ['text' => $item['symbol1']];

		if ($item['symbol2'] !== '')
			$listItem ['t1']['suffix'] = $item['symbol2'];

		$listItem ['t2'] = $item['personName'];

		$listItem ['i1'] = ['prefix' => $this->currencies [$item['currency']]['shortcut'], 'text' => utils::nf($item['request'] - $item['payment'], 2)];

		$q [] = 'SELECT [date], [currency]  FROM e10doc_balance_journal';
		array_push ($q, ' WHERE pairId = %s AND side = 0 LIMIT 1', $item['pairId']);

		$r = $this->app()->db->query ($q)->fetch ();
		if ($r['date'])
		{
			$listItem ['i2'] = utils::datef($r['date'], '%d');
			if ($this->operation == 1090001)
			{ // Zápočet pohledávky
				$listItem ['data-cc']['dateDue'] = utils::datef ($r['date'], '%d');
				$listItem ['data-cc']['credit'] = $item['request'] - $item['payment'];
				if ($this->currency !== $item['currency'])
					$listItem ['data-cc']['bankRequestAmount'] = $listItem ['data-cc']['credit'];
			}
			elseif ($this->operation == 1090002)
			{ // Zápočet závazku
				$listItem ['data-cc']['dateDue'] = utils::datef ($r['date'], '%d');
				$listItem ['data-cc']['debit'] = $item['request'] - $item['payment'];
				if ($this->currency !== $item['currency'])
					$listItem ['data-cc']['bankRequestAmount'] = $listItem ['data-cc']['debit'];
			}
			elseif ($this->operation == 1090011)
			{ // Kurz. rozdíl pohledávky
				$listItem ['data-cc']['dateDue'] = utils::datef($r['date'], '%d');

				if ($item['request'] > $item['payment'])
					$listItem ['data-cc']['credit'] = $item['request'] - $item['payment'];
				else
					$listItem ['data-cc']['debit'] = abs($item['request'] - $item['payment']);

				$listItem ['data-cc']['currency'] = $r['currency'];
			}
			elseif ($this->operation == 1090001)
			{ // Zápočet pohledávky
				$listItem ['data-cc']['dateDue'] = utils::datef($r['date'], '%d');
				$listItem ['data-cc']['credit'] = $item['request'] - $item['payment'];
				if ($this->currency !== $item['currency'])
					$listItem ['data-cc']['bankRequestAmount'] = $listItem ['data-cc']['credit'];
			}
			elseif ($this->operation == 1090012)
			{ // Kurz. rozdíl závazku
				$listItem ['data-cc']['dateDue'] = utils::datef($r['date'], '%d');

				if ($item['request'] > $item['payment'])
					$listItem ['data-cc']['debit'] = $item['request'] - $item['payment'];
				else
					$listItem ['data-cc']['credit'] = abs($item['request'] - $item['payment']);

				$listItem ['data-cc']['currency'] = $r['currency'];
			}
			elseif ($this->operation == 1030101 || $this->operation == 1030102)
			{ // Příkaz k úhradě || Příkaz k inkasu
				$listItem ['data-cc']['bankAccount'] = $item['bankAccount'];
				$listItem ['data-cc']['priceItem'] = $item['request'] - $item['payment'];
			}
			elseif ($this->currency !== $item['currency'])
				$listItem ['data-cc']['bankRequestAmount'] = $item['request'] - $item['payment'];
		}

		return $listItem;
	}

	public function selectRows2 ()
	{
		if (count($this->pairIds) && $this->queryParam('docType') === 'bankorder')
		{
			$q [] = 'SELECT journal.pairId as pairId, heads.title as titleHead, [rows].text as titleRow';
			array_push($q, '	FROM [e10doc_balance_journal] AS journal');
			array_push($q, '	LEFT JOIN e10doc_core_heads as heads ON journal.docHead = heads.ndx');
			array_push($q, '	LEFT JOIN e10doc_core_rows as [rows] ON journal.docLine = [rows].ndx');
			array_push($q, ' WHERE pairId IN %in', $this->pairIds);
			array_push($q, ' AND side = %i', 0);
			array_push($q, ' ORDER BY heads.dateAccounting');

			$rows = $this->db()->query ($q);
			foreach ($rows as $r)
			{
				if ($r['titleRow'])
					$this->texts[$r['pairId']] = $r['titleRow'];
				else
				if ($r['titleHead'])
					$this->texts[$r['pairId']] = $r['titleHead'];
			}
			unset ($q);
		}

		if ($this->balanceId == 2000)
		{
			$q[] = 'SELECT [rows].*, heads.dateDue as dateDueHead FROM [e10doc_core_rows] AS [rows]';
			array_push($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');

			array_push($q, ' WHERE 1');
			array_push($q, ' AND heads.docType = %s', 'bankorder');
			array_push($q, ' AND heads.docStateMain <= %i', 2);
			array_push($q, ' ORDER BY heads.dateAccounting DESC, [rows].ndx');
			array_push($q, ' LIMIT 1000');

			$rows = $this->app->db()->query($q);
			foreach ($rows as $r)
			{
				$pairId = $this->balanceId . '_' . $r['person'] . '_' . $r['symbol1'] . '_' . $r['symbol2'] . '_' . $r['currency'];

				$paymentInfo = ['amount' => $r['priceAll'], 'currency' => $r['currency']];

				if (!utils::dateIsBlank($r['dateDue']))
					$paymentInfo['dateDue'] = $r['dateDue'];
				else
					$paymentInfo['dateDue'] = $r['dateDueHead'];

				$this->paymentInfo[$pairId][] = $paymentInfo;
			}
		}
	}

	function decorateRow (&$item)
	{
		if (isset($this->texts[$item['pairId']]))
			$item ['data-cc']['text'] = $this->texts[$item['pairId']];

		if (isset($this->paymentInfo[$item['pairId']]))
		{
			$paymentInfo = [];
			foreach ($this->paymentInfo[$item['pairId']] as $pi)
			{
				$paymentInfo[] = [
					'text' => utils::nf($pi['amount'], 2), 'class' => 'e10-warning2 tag',
					'prefix' => 'PP '.utils::datef ($pi['dateDue'], '%d'),
					'suffix' => $this->currencies[$pi['currency']]['shortcut']
				];
			}
			$item['t3'] = $paymentInfo;
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		// -- balance
		$balanceId = 0;
		$this->operation = $this->queryParam ('operation');
		$this->currency = $this->queryParam ('currency');
		$operationId = $this->table->app()->cfgItem ('e10.docs.operations.'.$this->operation, FALSE);
		if ($operationId !== FALSE)
		{
			if (isset ($operationId['paymentBalance']))
				$balanceId = intval ($operationId['paymentBalance']);
			else
				if ($this->queryParam ('operation') == 1099998)
				{
					$itemNdx = intval($this->queryParam ('item'));
					if ($itemNdx)
						$balanceId = $this->db()->query ('SELECT useBalance FROM e10_witems_items WHERE ndx = %i', $itemNdx)->fetchSingle();
				}
		}
		$this->balanceId = $balanceId;

		$balanceCfg = $this->app()->cfgItem ('e10.balance');
		if (isset($balanceCfg[$balanceId]['type']) && $balanceCfg[$balanceId]['type'] === 'hc')
			$this->forceHc = TRUE;
		elseif ($this->operation == 1090011 || $this->operation == 1090012)
			$this->forceHc = TRUE;

		$q [] = 'SELECT';
		if ($this->forceHc)
			array_push ($q, ' SUM(journal.requestHc) as request, SUM(journal.paymentHc) as payment,',
				' journal.homeCurrency as currency,');
		else
			array_push ($q, ' SUM(journal.request) as request, SUM(journal.payment) as payment, journal.currency,');
		array_push ($q, ' journal.symbol1, journal.symbol2, journal.symbol3,');
		array_push ($q, '	journal.pairId as pairId, journal.person as person, journal.bankAccount as bankAccount, persons.fullName AS personName');
		array_push ($q, '	FROM [e10doc_balance_journal] AS journal');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON journal.person = persons.ndx');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON journal.docHead = heads.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->operation == 1090011 || $this->operation == 1090012)
			array_push ($q, ' AND journal.currency != journal.homeCurrency');

		if ($balanceId)
			array_push ($q, ' AND [type] = %i', $balanceId);

		// -- fiscalYear
		$fiscalYear = E10Utils::todayFiscalYear($this->app(), $this->queryParam ('dateAccounting'));
		if ($fiscalYear)
			array_push ($q, ' AND journal.[fiscalYear] = %i', $fiscalYear);

		array_push ($q, ' GROUP BY [pairId]');

		array_push ($q, ' HAVING [payment] != [request]');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND (");
			array_push ($q, " journal.[symbol1] LIKE %s", $fts.'%');
			array_push ($q, " OR journal.[symbol2] LIKE %s", $fts.'%');
			array_push ($q, " OR persons.[fullName] LIKE %s", '%'.$fts.'%');
			array_push ($q, ")");
		}

		array_push ($q, ' ORDER BY persons.[fullName], symbol1, symbol2' . $this->sqlLimit());

		$this->runQuery ($q);
	}
}

<?php

namespace e10doc\finance;

use E10Doc\Core\e10utils, e10\utils, \E10\DbTable;


/**
 * Class TableTransactions
 * @package e10doc\finance
 */
class TableTransactions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.finance.transactions', 'e10doc_finance_transactions', 'Platební transakce');
	}
}


/**
 * Class ViewTransactions
 * @package e10doc\finance
 */
class ViewTransactions extends \E10\TableViewGrid
{
	var $docTypes;
	var $currencies;

	var $types = [1 => 'Příjem', 2 => 'Výdej'];

	public function init ()
	{
		$this->docTypes = $this->table->app()->cfgItem ('e10.docs.types');
		$this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');

		$this->topParams = new \e10doc\core\libs\GlobalParams ($this->table->app());
		$this->topParams->addParam ('float', 'queryAmount', ['title' => 'Částka', 'colWidth' => 2]);
		$this->topParams->addParam ('float', 'queryAmountDiff', ['title' => '+/-', 'colWidth' => 1]);

		parent::init();

		$g = [
			'type' => 'Směr',
			'id' => ' ID',
			'date' => '_Datum',
			'amount' => ' Částka',
			'currency' => 'Měna',
			'symbol1' => 'VS', 'symbol2' => 'SS', 'symbol3' => 'KS',
			'account' => '_Účet',
			'cb' => ' Zůstatek',
			'note' => '_Pozn.'
		];

		$this->setGrid ($g);

		$this->setInfo('title', 'Platební transakce');
		$this->setInfo('icon', 'icon-money');
	}

	public function createToolbar (){return array();}

	public function renderRow ($item)
	{
		$listItem ['type'] = $this->types[$item['type']];
		$listItem ['id'] = $item['bankTransId'];
		$listItem ['date'] = ['text' => utils::datef ($item['dateTime'], '%d'), 'suffix' => utils::datef ($item['dateTime'], '%T')];
		$listItem ['symbol1'] = $item['symbol1'];
		$listItem ['symbol2'] = $item['symbol2'];
		$listItem ['symbol3'] = $item['symbol3'];
		$listItem ['amount'] = $item['amount'];
		$listItem ['account'] = $item['bankAccount'];
		$listItem ['cb'] = $item['closingBalance'];
		$listItem ['note'] = $item['note'];
		$listItem ['currency'] = $this->currencies[$item['currency']]['shortcut'];
		$listItem ['_options'] = ['cellClasses' => ['note' => 'e10-small']];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT trans.* FROM [e10doc_finance_transactions] AS trans';
		array_push ($q, ' WHERE 1');

		// -- amount
		if ($this->topParamsValues['queryAmount']['value'] !== '')
		{
			$column = 'amount';
			$paramValueName = e10utils::amountQuery ($q, $column, $this->topParamsValues['queryAmount']['value'], $this->topParamsValues['queryAmountDiff']['value']);
			if ($paramValueName !== '')
				$this->setInfo('param', 'Částka', $paramValueName);
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND (");
			array_push ($q, " trans.[symbol1] LIKE %s", $fts.'%');
			array_push ($q, " OR trans.[symbol2] LIKE %s", $fts.'%');
			array_push ($q, " OR trans.[bankAccount] LIKE %s", $fts.'%');
			array_push ($q, " OR trans.[note] LIKE %s", '%'.$fts.'%');
			array_push ($q, ")");

			$this->setInfo('param', 'Hledaný text', $fts);
		}

		array_push ($q, ' ORDER BY [ndx] DESC' . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows
}


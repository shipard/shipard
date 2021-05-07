<?php

namespace demo\documents\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/balance/balance.php';

use \e10\utils, \e10doc\core\e10utils, \e10doc\balance\reportBalance;


/**
 * Class Bank
 * @package lib\demo\documents\docs
 */
class Bank extends \demo\documents\libs\Core
{
	var $statementDate;
	var $todayDate;
	var $statementBalance = 0.0;

	public function init ($taskDef, $taskTypeDef)
	{
		parent::init($taskDef, $taskTypeDef);

		$this->todayDate = utils::today();
		$this->statementDate = utils::today();

		$this->data['rec']['docType'] = 'bank';
		$this->data['rec']['myBankAccount'] = 1;
		$this->data['rec']['currency'] = 'czk';
		$this->data['rec']['author'] = 1;

		$this->data['rec']['datePeriodBegin'] = $this->statementDate;
		$this->data['rec']['datePeriodEnd'] = $this->statementDate;

		//$this->data['rec']['person'] = 0;

		$this->loadLastStatement();

		$this->addRows();
	}

	function loadLastStatement()
	{
		$fiscalYear = e10utils::todayFiscalYear($this->app, $this->statementDate);

		$q[] = 'SELECT * FROM [e10doc_core_heads]';
		array_push ($q, ' WHERE [docType] = %s', 'bank', ' AND [myBankAccount] = %i', $this->data['rec']['myBankAccount']);
		array_push ($q, ' AND [docState] NOT IN %in', [4100, 9800]);
		array_push ($q, ' ORDER BY [datePeriodEnd] DESC, docOrderNumber DESC');
		array_push ($q, ' LIMIT 1');

		$r = $this->db()->query ($q)->fetch ();
		if ($r)
		{
			if ($r['fiscalYear'] === $fiscalYear)
				$this->data['rec']['docOrderNumber'] = $r['docOrderNumber'] + 1;
			else
				$this->data['rec']['docOrderNumber'] = 1;

			$this->data['rec']['initBalance'] = $r['balance'];
			$this->statementBalance = $r['balance'];
		}
		else
		{
			$this->data['rec']['docOrderNumber'] = 1;
		}
	}

	function addRows ()
	{
		$this->addRowsReceivables();
		$this->addRowsObligations();
	}

	function addRowsReceivables ()
	{
		$report = new \e10doc\balance\reportBalanceReceivables ($this->app);
		$report->init();
		$report->mode = reportBalance::bmNormal;
		$report->disableSums = TRUE;
		$data = $report->prepareData();
		//echo "\n\n".json_encode($data)."\n\n";

		foreach ($data as $pairId => $r)
		{
			$daysLeft = utils::dateDiff($this->todayDate, $r['date']);

			if ($daysLeft > 2)
				continue;

			$x = substr ($r['s1'], -1, 1);
			if ($x[0] === '1' && mt_rand(0, 100) > 5)
				continue;
			if ($x[0] === '5' && mt_rand(0, 100) > 50)
				continue;

			$row = [];
			$row['credit'] = $r['restHc'];
			$row['person'] = $r['person'];
			$row['symbol1'] = $r['s1'];

			$this->statementBalance += $row['credit'];

			$this->addRow($row);
		}
	}

	function addRowsObligations ()
	{
		$report = new \e10doc\balance\reportBalanceObligations ($this->app);
		$report->init();
		$report->mode = reportBalance::bmNormal;
		$report->disableSums = TRUE;
		$data = $report->prepareData();
		//echo "\n\n".json_encode($data)."\n\n";

		foreach ($data as $pairId => $r)
		{
			$daysLeft = utils::dateDiff($this->todayDate, $r['date']);

			if ($daysLeft > 2)
				continue;

			if ($this->statementBalance - $r['restHc'] < 1000.00)
				continue;

			$x = substr ($r['s1'], -1, 1);
			if ($x[0] === '3' && mt_rand(0, 100) > 5)
				continue;
			if ($x[0] === '7' && mt_rand(0, 100) > 50)
				continue;

			$row = [];
			$row['debit'] = $r['restHc'];
			$row['person'] = $r['person'];
			$row['symbol1'] = $r['s1'];

			$this->statementBalance -= $row['debit'];

			$this->addRow($row);
		}
	}

	protected function setPerson ()
	{
	}

	public function save()
	{
		if (!count($this->data['rows']))
			return;

		parent::save();
	}
}

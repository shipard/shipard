<?php

namespace E10Doc\Bank\Import\cz_mt940;

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';

use \E10\Application, E10\Wizard, E10\utils;


/**
 * Class Import
 * @package E10Doc\Bank\Import\cz_mt940
 */
class Import extends \E10Doc\Bank\ebankingImportDoc
{
	public function import ()
	{
		$parser = new \lib\ebanking\parsers\mt940($this->app());

		$parser->parseText ($this->textData);

		foreach ($parser->statements as $s)
		{
			$this->importStatement($s);
		}
	}

	public function importStatement ($s)
	{
		$this->setHeadInfo ('bankAccount', $s['myBankAccount']);
		$this->setHeadInfo ('initBalance', $s['balanceBeginAmount']);
		$this->setHeadInfo ('docOrderNumber', $s['statementNumber']);
		$this->setHeadInfo ('datePeriodBegin', $s['balanceBeginDate']);
		$this->setHeadInfo ('datePeriodEnd', $s['balanceEndDate']);

		foreach ($s['transactions'] as $t)
		{
			$money = $t['amount'];
			if ($t['dirKind'] === 'D')
				$money = - $money;

			$this->setRowInfo ('bankAccount', $t['account']);
			$this->setRowInfo ('money', $money);
			$this->setRowInfo ('symbol1', $t['symbol1Imported']);
			$this->setRowInfo ('symbol2', $t['symbol2Imported']);
			$this->setRowInfo ('symbol3', $t['symbol3Imported']);
			$this->setRowInfo ('memo', implode(' ', $t['memos']));
			$this->setRowInfo ('dateDue', $t['dateStr']);

			$this->appendRow();
		}
	}
}

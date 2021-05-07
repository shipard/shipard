<?php

namespace lib\ebanking\transactions;

require_once __APP_DIR__ . '/e10-modules/e10doc/bank/bank.php';


use E10\utils, E10\Utility;


/**
 * Class DownloadBankTransactions
 * @package lib\ebanking\transactions
 */
class DownloadBankTransactions extends Utility
{
	protected $bankAccountCfg;
	protected $bankAccountRec;
	protected $bankAccountNdx;
	protected $transactionsData = FALSE;


	public function init ()
	{
	}

	public function setBankAccount ($bankAccountCfg)
	{
		$this->bankAccountCfg = $bankAccountCfg;
		$this->bankAccountNdx = intval ($this->bankAccountCfg['ndx']);
		$this->bankAccountRec = $this->app->loadItem($this->bankAccountCfg['ndx'], 'e10doc.base.bankaccounts');
	}

	public function addTransaction ($item)
	{
		$item['myBankAccount'] = $this->bankAccountNdx;

		$this->db()->query ('INSERT INTO [e10doc_finance_transactions] ', $item);

		$listsClasses = $this->app->cfgItem ('registeredClasses.financeTransactionInfo', []);
		foreach ($listsClasses as $class)
		{
			$classId = $class['classId'];
			$object = $this->app->createObject($classId);
			$object->init ();
			$object->setTransaction ($item);
			$object->run ();
		}
	}
}

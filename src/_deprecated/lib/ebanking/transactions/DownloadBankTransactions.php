<?php

namespace lib\ebanking\transactions;

require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';


use \Shipard\Base\Utility;


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
		$newNdx = $this->db()->getInsertId();
		$item['ndx'] = $newNdx;

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

<?php

namespace lib\ebanking\transactions;

use e10\utils, e10\Utility, e10pro\wkf\TableMessages;
use wkf\core\TableIssues;


/**
 * Class DownloadBankTransactionsInbox
 * @package lib\ebanking\download
 */
class DownloadBankTransactionsInbox extends \lib\ebanking\transactions\DownloadBankTransactions
{
	protected $inboxQueryParams = [];

	protected function downloadTransactions ()
	{
		$q[] = 'SELECT * FROM [e10pro_wkf_messages] as msgs';
		array_push($q, ' WHERE [msgType] = %i', TableIssues::mtInbox);
		array_push($q, ' AND [docState] = %i', 1000);

		if (isset($this->inboxQueryParams['subject']))
			array_push($q, ' AND [subject] = %s', $this->inboxQueryParams['subject']);

		if (isset($this->inboxQueryParams['emailFrom']))
			array_push ($q,
				' AND EXISTS (SELECT ndx FROM e10_base_properties WHERE',
					' msgs.ndx = e10_base_properties.recid AND valueString = %s', $this->inboxQueryParams['emailFrom'],
					' AND tableid = %s', 'e10pro.wkf.messages',
					' AND property = %s', 'eml-from',
				')');

		array_push($q, ' ORDER BY msgs.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $row)
		{
			$this->transactionsData[$row['ndx']] = $row['text'];
		}
	}

	public function addTransactions ()
	{
		if ($this->transactionsData === FALSE)
			return;

		foreach ($this->transactionsData as $inboxNdx => $oneTransaction)
		{
			$newItem = $this->parseTransaction($oneTransaction);
			if ($newItem === FALSE)
			{
				continue;
			}

			$this->addTransaction($newItem);
			$this->updateInbox($inboxNdx, ['docState' => 9000, 'docStateMain' => 5]);
		}
	}

	protected function parseTransaction ($td)
	{
		return FALSE;
	}

	protected function updateInbox ($inboxNdx, $fields)
	{
		$this->db()->query ('UPDATE [e10pro_wkf_messages] SET ', $fields, ' WHERE ndx = %i', $inboxNdx);
		if (isset($fields['docState']))
		{
			$tableMessages = $this->app->table ('e10pro.wkf.messages');
			$tableMessages->docsLog ($inboxNdx);
		}
	}

	public function run ()
	{
		$this->downloadTransactions();
		$this->addTransactions();
	}
}

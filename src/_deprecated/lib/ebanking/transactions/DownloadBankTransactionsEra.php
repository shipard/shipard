<?php

namespace lib\ebanking\transactions;

use e10\utils, e10\str;


/**
 * Class DownloadBankTransactionsEra
 * @package lib\ebanking\transactions
 */
class DownloadBankTransactionsEra extends \lib\ebanking\transactions\DownloadBankTransactionsInbox
{
	public function init ()
	{
		parent::init();

		//$this->inboxQueryParams['subject'] = 'Era info: Zaúčtování transakce';
		$this->inboxQueryParams['emailFrom'] = 'era.info@erasvet.cz';
		//$this->inboxQueryParams['attachmentSuffix'] = '.TXT';
	}

	protected function parseTransaction ($td)
	{
		$tr = ['bankAccount' => '', 'bankTransId' => 0];

		$bankAccount = '';

		$rows = preg_split("/\\r\\n|\\r|\\n/", $td);
		foreach ($rows as $r)
		{
			if (str::substr($r, 0, 3) === 'VS ')
				$tr['symbol1'] = str::substr($r, 3);
			else
			if (str::substr($r, 0, 3) === 'SS ')
				$tr['symbol2'] = str::substr($r, 3);
			else
			if (str::substr($r, 0, 7) === 'částka ')
			{
				$m = str::substr($r, 7);
				$parts = explode (' ', $m);
				$tr['amount'] = str_replace(',', '.', $parts[0]);
				$tr['currency'] = str::tolower ($parts[1]);
			}
			else
			if (str::substr($r, 0, 42) === 'Zůstatek na účtu po zaúčtování transakce: ')
			{
				$m = str::substr($r, 42);
				$parts = explode (' ', $m);
				$tr['closingBalance'] = str_replace(',', '.', $parts[0]);
			}
			else
			if (str::substr($r, 0, 4) === 'dne ')
			{
				$info = str::substr($r, 4);
				$parts = explode (' ', $info);

				$dateStr = $parts[0].$parts[1].$parts[2].' 00:00:00';
				$tr['date'] = date_create_from_format ('d.m.Y H:m:s', $dateStr);

				$tr['note'] = str::upToLen($r, 180);

				$bankAccount = $parts[6].'/0300';
			}
		}

		if ($this->bankAccountCfg ['bankAccount'] !== $bankAccount)
		{
			return FALSE;
		}

		if (isset ($tr['amount']))
		{
			$tr['dateTime'] = new \DateTime();
			$tr['type'] = ($tr['amount'] < 0.0) ? 2 : 1;

			return $tr;
		}
		return FALSE;
	}
}

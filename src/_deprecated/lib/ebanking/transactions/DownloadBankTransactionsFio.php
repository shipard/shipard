<?php

namespace lib\ebanking\transactions;

use \Shipard\Utils\Utils;
use \Shipard\Utils\Str;


/**
 * Class DownloadBankTransactionsFio
 * @package lib\ebanking\download
 *
 * @url http://www.fio.cz/docs/cz/API_Bankovnictvi.pdf
 *
 */
class DownloadBankTransactionsFio extends \lib\ebanking\transactions\DownloadBankTransactions
{
	protected $myBankAccountInfo = FALSE;

	protected function downloadTransactions ()
	{
		// https://www.fio.cz/ib_api/rest/last/API-TOKEN/transactions.xml
		$url = 'https://www.fio.cz/ib_api/rest/last/'.$this->bankAccountRec['apiTokenTransactions'].'/'.'transactions.json';

		$tmpFileName = Utils::tmpFileName('json');

		$data = @file_get_contents($url);
		if ($data === FALSE)
			return;
		file_put_contents($tmpFileName, $data);

		$this->transactionsData = json_decode($data, TRUE);
	}

	public function addTransactions ()
	{
		if ($this->transactionsData === FALSE)
			return;

		if (!isset ($this->transactionsData['accountStatement']['transactionList']) || !$this->transactionsData['accountStatement']['transactionList'])
			return;

		$this->myBankAccountInfo = $this->transactionsData['accountStatement']['info'];
		$openingBalance = $this->transactionsData['accountStatement']['info']['openingBalance'];
		$closingBalance = $openingBalance;
		foreach ($this->transactionsData['accountStatement']['transactionList']['transaction'] as $r)
		{
			$newItem = [];

			$newItem['amount'] = $r['column1']['value'];
			$newItem['bankTransId'] = $r['column22']['value'];

			$closingBalance += $r['column1']['value'];
			$newItem['closingBalance'] = $closingBalance;

			if ($newItem['amount'] < 0.0)
				$type = 2;
			else
				$type = 1;
			$newItem['type'] = $type;

			$newItem['date'] = new \DateTime($r['column0']['value']);
			$newItem['dateTime'] = new \DateTime();

			$newItem['currency'] = strtolower($r['column14']['value']);

			$newItem['bankAccount'] = $r['column2']['value'].'/'.$r['column3']['value'];
			$newItem['bankAccount'] = ltrim ($newItem['bankAccount'], '0'); // strip leading zeros and blank account prefix
			$newItem['bankAccount'] = ltrim ($newItem['bankAccount'], '-');
			$newItem['bankAccount'] = ltrim ($newItem['bankAccount'], '0');

			$newItem['symbol1'] = isset($r['column5']) ? $r['column5']['value'] : '';
			$newItem['symbol2'] = isset($r['column6']) ? $r['column6']['value'] : '';
			$newItem['symbol3'] = isset($r['column4']) ? $r['column4']['value'] : '';
			if ($newItem['symbol3'] === '0000')
				$newItem['symbol3'] = '';

			$notes = [];
			if (isset($r['column16']))
				$notes[] = $r['column16']['value'];
			if (isset($r['column7']))
				$notes[] = $r['column7']['value'];
			if (isset($r['column8']))
				$notes[] = $r['column8']['value'];

			$newItem['note'] = Str::upToLen(implode(', ', $notes), 180);

			$this->addTransaction($newItem);
		}
	}

	public function run ()
	{
		if (!isset($this->bankAccountRec['apiTokenTransactions']) || $this->bankAccountRec['apiTokenTransactions'] === '')
			return;

		$this->downloadTransactions();
		$this->addTransactions();
	}
}

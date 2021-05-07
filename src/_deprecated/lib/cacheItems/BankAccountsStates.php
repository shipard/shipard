<?php

namespace lib\cacheItems;


/**
 * Class BankAccountsStates
 * @package lib\cacheItems
 */
class BankAccountsStates extends \Shipard\Base\CacheItem
{
	function createData ()
	{
		$bankAccounts = $this->app->cfgItem ('e10doc.bankAccounts', FALSE);
		if ($bankAccounts === FALSE)
		{
			return;
		}

		$this->data['totalHc'] = 0.0;
		foreach ($bankAccounts as $baNdx => $baCfg)
		{
			if ($baCfg['efd'])
				continue;

			$q [] = 'SELECT * FROM e10doc_core_heads';
			array_push($q, ' WHERE docType = %s', 'bank', ' AND myBankAccount = %i', $baNdx, ' AND docState = %i', 4000);
			array_push($q, ' ORDER BY dateAccounting DESC, docOrderNumber DESC');
			array_push($q, ' LIMIT 0, 1');

			$lastDoc = $this->app->db()->query ($q)->fetch();
			unset ($q);

			if (!$lastDoc)
				continue;

			$currId = $baCfg['curr'];
			$this->data['oneCurrId'] = $currId;
			$currShortcut = $this->app->cfgItem ('e10.base.currencies.'.$currId.'.shortcut');

			$accBalance = [
					'title' => $baCfg['shortName'], 'curr' => $currShortcut, 'balance' => $lastDoc['balance'],
					'date' => $lastDoc['dateAccounting']->format ('Y-m-d')
			];
			$this->data['accounts'][] = $accBalance;

			$this->data['totalHc'] += $lastDoc['balance'];

			if (!isset ($this->data['sums'][$currId]))
				$this->data['sums'][$currId] = ['balance' => 0.0, 'curr' => $currShortcut];
			$this->data['sums'][$currId]['balance'] += $lastDoc['balance'];

			$this->data['sums'][$currId]['date'] = $lastDoc['dateAccounting']->format('Y-m-d');
		}

		parent::createData();
	}

}

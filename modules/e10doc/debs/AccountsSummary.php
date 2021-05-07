<?php

namespace e10doc\debs;

use e10\Utility, e10\str;


/**
 * Class AccountsSummary
 * @package e10doc\debs
 */
class AccountsSummary extends Utility
{
	var $project = 0;
	var $person = 0;

	var $tableAccounts;
	var $accountKindsEnum;

	var $all = [];
	var $accountsSummary = [];
	var $content = [];

	var $enabledAccountKinds = ['3', '2', '0'];
	var $enabledAccounts = ['0', '5', '6'];

	public function setProject ($project)
	{
		$this->project = $project;
	}

	public function setPerson ($person)
	{
		$this->person = $person;
	}

	protected function accountEnabled ($account)
	{
		foreach ($this->enabledAccounts as $a)
		{
			if (str::substr($account, 0, str::strlen($a)) === $a)
				return TRUE;
		}

		return FALSE;
	}

	protected function createSummary ()
	{
		$q[] = 'SELECT journal.accountId as accountId, accounts.shortName as accountName, accounts.accountKind,';
		array_push($q, ' SUM(journal.moneyDr) as moneyDr, SUM(journal.moneyCr) as moneyCr');

		array_push($q, ' FROM e10doc_debs_journal as journal LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND accounts.accountKind IN %in', $this->enabledAccountKinds);
		array_push($q, ' AND journal.fiscalType = %i', 0);

		if ($this->project !== 0)
			array_push($q, ' AND journal.project = %i', $this->project);
		if ($this->person !== 0)
			array_push($q, ' AND journal.person = %i', $this->person);

		array_push($q, ' AND (ABS(journal.moneyDr) > 1 OR ABS(journal.moneyCr) > 1)');
		array_push($q, ' GROUP BY journal.accountId, accounts.shortName');

		$rows = $this->app->db()->query($q);

		$data = [];
		forEach ($rows as $r)
		{
			if (!$this->accountEnabled($r['accountId']))
				continue;

			$ak = $r['accountKind'];
			$money = $r['moneyCr'] - $r['moneyDr'];
			$newRow = ['accountId' => $r['accountId'], 'money' => $money, 'text' => $r['accountName'], 'ak' => $ak];
			$data[$ak][$r['accountId']] = $newRow;
		}

		$this->accountsSummary = $data;

		$sumTotal = 0;
		foreach ($this->enabledAccountKinds as $ak)
		{
			if (!isset($data[$ak]) || !count($data[$ak]))
				continue;

			$this->all[] = ['accountId' => $this->accountKindsEnum[$ak], '_options' => ['class' => 'subheader separator', 'colSpan' => ['accountId' => 3]]];
			$total = 0.0;

			foreach ($data[$ak] as $tableRow)
			{
				$this->all[] = $tableRow;
				$total += $tableRow['money'];
				$sumTotal += $tableRow['money'];
			}

			if (count($data[$ak]) > 1)
				$this->all[] = ['accountId' => 'CELKEM', 'money' => $total,
						'_options' => ['class' => 'subtotal', 'colSpan' => ['accountId' => 2]]];
		}

		if (count($this->all))
		{
			$this->all[] = ['accountId' => 'VÝSLEDEK', '_options' => ['class' => 'subheader separator', 'colSpan' => ['accountId' => 3]]];
			$this->all[] = ['accountId' => '', 'money' => $sumTotal,
					'_options' => ['class' => 'sumtotal', 'colSpan' => ['accountId' => 2]]];
		}
	}

	public function run ()
	{
		$this->tableAccounts = $this->app->table ('e10doc.debs.accounts');
		$this->accountKindsEnum = $this->tableAccounts->columnInfoEnum ('accountKind');
		$this->createSummary();
	}
}

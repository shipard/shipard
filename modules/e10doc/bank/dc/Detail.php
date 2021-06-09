<?php

namespace e10doc\bank\dc;
use \e10\utils, e10pro\wkf\TableMessages, wkf\core\TableIssues;


/**
 * Class Detail
 * @package e10doc\core\dc
 */
class Detail extends \e10doc\core\dc\Detail
{
	public function docsRows ()
	{
		$item = $this->recData;

		$debsAccountIdCol = '';
		if ($this->table->app()->model()->table ('e10doc.debs.accounts') !== FALSE)
			$debsAccountIdCol = ', [debsAccountId]';

		$q = 'SELECT [operation], [person], [item], [itemBalance]'.$debsAccountIdCol.', [debit], [credit] FROM [e10doc_core_rows] AS [rows] WHERE [rows].document = %i ORDER BY ndx';

		$rows = $this->table->db()->query($q, $item ['ndx'])->fetchAll ();
		$list = array ();
		$totalCredit = 0.0;
		$totalDebit = 0.0;
		$balance = $item['initBalance'];

		$personsTable = array ();
		$itemsTable = array ();
		$accountsTable = array ();

		$list[] = array (
			'text' => 'Počáteční zůstatek', 'balance' => $balance, '_options' => array ('class' => 'subtotal', 'colSpan' => array ('text' => 3)));

		forEach ($rows as $r)
		{
			$newRow = $r;

			switch ($r['operation'])
			{
				case 1099999 :
					if ($this->table->app()->model()->table ('e10doc.debs.accounts') !== FALSE)
					{
						if (!isset ($sccountsTable[$r['debsAccountId']]))
						{
							$qAccount = 'SELECT [fullName] FROM [e10doc_debs_accounts] WHERE [id] = %s AND [docState] != 9800 LIMIT 1';
							$resAccount = $this->table->db()->query($qAccount, $r['debsAccountId'])->fetchAll ();

							foreach ($resAccount as $a)
							{
								$accountsTable[$r['debsAccountId']] = $a;
							}
						}
						$newRow['text'] = 'Účet: '.$r['debsAccountId'].' - '.$accountsTable[$r['debsAccountId']]['fullName'];
					}
					break;
				case 1099998 :
					if (!isset ($itemsTable[$r['item']]))
					{
						$tableItems = new \E10\Witems\TableItems ($this->app());
						$item = array ('ndx' => $r['item']);
						$itemsTable[$r['item']] = $tableItems->loadItem ($item);
					}
					if ($r['itemBalance'])
					{
						if (!isset ($personsTable[$r['person']]))
						{
							$tablePersons = new \E10\Persons\TablePersons ($this->app());
							$person = array ('ndx' => $r['person']);
							$personsTable[$r['person']] = $tablePersons->loadItem ($person);
						}
						$newRow['text'] = $itemsTable[$r['item']]['fullName'].': '.$personsTable[$r['person']]['fullName'];
					}
					else
						$newRow['text'] = $itemsTable[$r['item']]['fullName'];
					break;
				default :
					if (!isset ($personsTable[$r['person']]))
					{
						$tablePersons = new \E10\Persons\TablePersons ($this->app());
						$person = array ('ndx' => $r['person']);
						$personsTable[$r['person']] = $tablePersons->loadItem ($person);
					}
					$operationName = '';
					if ($r['operation'] <> 1030001 AND $r['operation'] <> 1030002)
					{
						$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $r['operation'], FALSE);
						$operationName = $operation['title'].': ';
					}
					$newRow['text'] = $operationName.$personsTable[$r['person']]['fullName'];
					break;
			}

			$totalCredit += $r['credit'];
			$totalDebit += $r['debit'];
			$balance = round($balance+$r['credit']-$r['debit'], 2);
			$newRow['balance'] = $balance;
			if ($newRow['credit'] == 0.0)
				unset ($newRow['credit']);
			if ($newRow['debit'] == 0.0)
				unset ($newRow['debit']);

			$list[] = $newRow;
		}

		if (count ($rows))
		{
			$list[] = array (
				'text' => 'Zůstatek', 'balance' => $balance, '_options' => array ('class' => 'subtotal', 'colSpan' => array ('text' => 3)));
			$list[] = array (
				'credit' => $totalCredit, 'debit' => $totalDebit, '_options' => array ('class' => 'sum'));
		}

		$h = ['#' => '#', 'text' => 'Popis', 'debit' => ' Vyplaceno', 'credit' => ' Přijato', 'balance' => ' Zůstatek'];
		return ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky dokladu'], 'header' => $h, 'table' => $list];
	}

	public function createContentXXX ()
	{
		$this->createContentHeader ();
		$this->createContentBody ();
		$this->createTitle();
	}

}

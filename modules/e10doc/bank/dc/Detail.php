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
		$testNewBankDocDetail = $this->app()->cfgItem ('options.experimental.testNewBankDocDetail', 0);

		if ($testNewBankDocDetail)
		{
			return $this->docsRowsNew();
		}

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

	public function docsRowsNew ()
	{
		$item = $this->recData;

		$q = [];
		array_push($q, 'SELECT [rows].*,');
		array_push($q, ' [persons].fullName AS personFullName');
		array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [rows].[person] = [persons].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [rows].document = %i', $item ['ndx']);
		array_push($q, ' ORDER BY [rows].rowOrder, [rows].ndx');
		//array_push($q, '');

		$list = [];
		$totalCredit = 0.0;
		$totalDebit = 0.0;
		$balance = $item['initBalance'];

		$itemsTable = [];
		$accountsTable = [];


		$rowsSettings = new \e10doc\helpers\RowsSettings($this->app());

		// $list[] = ['text' => 'Počáteční zůstatek', 'balance' => $balance, '_options' => ['class' => 'subtotal', 'colSpan' => ['text' => 3]]];

		$rows = $this->table->db()->query($q);
		forEach ($rows as $r)
		{
			$newRow = [
				'debit' => $r['debit'],
				'credit' => $r['credit'],
				'rs' => ['cntApplies' => 0, 'settings' => []],
			];

			$rowSettingsRow = $r->toArray();
			$rowsSettings->run ($rowSettingsRow, $this->recData);

			if ($rowsSettings->cntApplies)
			{
				$newRow['rs']['cntApplies'] += $rowsSettings->cntApplies;
			}

			$personNdx = $r['person'];
			$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $r['operation'], NULL);

			$newRow['txt'] = [];

			if ($r['operation'] == 1099999)
			{ // účetní zápis
				if (!isset ($sccountsTable[$r['debsAccountId']]))
				{
					$qAccount = 'SELECT [fullName] FROM [e10doc_debs_accounts] WHERE [id] = %s AND [docState] != 9800 LIMIT 1';
					$resAccount = $this->table->db()->query($qAccount, $r['debsAccountId'])->fetchAll ();

					foreach ($resAccount as $a)
					{
						$accountsTable[$r['debsAccountId']] = $a;
					}
				}
				$newRow['txt'] = [['text' => 'Účet: '.$r['debsAccountId'].' - '.$accountsTable[$r['debsAccountId']]['fullName']]];
			}
			elseif ($r['operation'] == 1099998)
			{	// accItem
				if (!isset ($itemsTable[$r['item']]))
				{
					$tableItems = new \E10\Witems\TableItems ($this->app());
					$item = array ('ndx' => $r['item']);
					$itemsTable[$r['item']] = $tableItems->loadItem ($item);
				}
				if ($r['itemBalance'])
				{
					$newRow['txt'] = [['text' => $itemsTable[$r['item']]['fullName'].': '.$r['personFullName']]];
				}
				else
					$newRow['txt'] = [['text' => $itemsTable[$r['item']]['fullName']]];
			}
			elseif ($r['operation'] == 1030001 || $r['operation'] == 1030002)
			{
				if (!$personNdx)
				{
					$newRow['txt'][] = ['text' => 'Není zadána Osoba', 'class' => 'label label-warning'];
				}
				else
				{
					$newRow['txt'] = [
						['text' => $r['personFullName'], 'class' => ''],
					];
				}
			}
			else
			{
				$operationName = '';
				if ($r['operation'] <> 1030001 AND $r['operation'] <> 1030002) // není úhrada pohledávky ani úhrada závazku
				{
					$operationName = $operation['title'].': ';
				}
				$newRow['txt'] = [
					['text' => $operationName.$r['personFullName'], 'class' => ''],
				];
			}

			$newRow['txt'][] = ['text' => '', 'class' => 'break'];

			if ($r['symbol1'] !== '')
				$newRow['txt'][] = ['text' => $r['symbol1'], 'prefix' => 'vs', 'class' => 'label label-default'];
			if ($r['symbol2'] !== '')
				$newRow['txt'][] = ['text' => $r['symbol2'], 'prefix' => 'ss', 'class' => 'label label-default'];


			$newRow['txt'][] = ['text' => $r['text'], 'class' => 'e10-off block'];

			//if ($newRow['rs']['cntApplies'])
			{
				foreach ($rowsSettings->usedRowsSettings as $rsNdx => $rs)
				{
					$label = [
						'text' => $rs['rsRecData']['name'], 'class' => 'label label-danger', 'icon' => 'system/iconCogs',
					];
					if (!count($rs['changes']))
					{
						$label['class'] = 'label label-success';
						$label['title'] = json_encode($rs['applies']);
					}
					else
					{
						$label['title'] = json_encode($rs['changes']);
					}
					$newRow['txt'][] = $label;
				}
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

		if (count ($list))
		{
			//$list[] = ['text' => 'Zůstatek', 'balance' => $balance, '_options' => ['class' => 'subtotal', 'colSpan' => ['text' => 3]]];
			$list[] = ['credit' => $totalCredit, 'debit' => $totalDebit, '_options' => ['class' => 'sum']];
		}

		$h = [
			'#' => '#',
			'txt' => 'Popis',
			'debit' => ' Vyplaceno', 'credit' => ' Přijato',
			//'balance' => ' Zůstatek',
		];

		$title = [
			['icon' => 'system/iconList', 'text' => 'Řádky dokladu', 'class' => 'h2'],
		];

		$title [] = [
			'type' => 'action', 'action' => 'addwizard',
			'text' => 'Přeúčtovat', 'data-class' => 'e10doc.core.libs.ReAccountingWizard',
			'icon' => 'cmnbkpRegenerateOpenedPeriod',
			'class' => 'pull-right',
			'actionClass' => 'btn btn-warning btn-xs'
		];
		$title[] = ['text' => '', 'class' => 'block pb-1'];

		return [
			'pane' => 'e10-pane e10-pane-table',
			'type' => 'table',
			'paneTitle' => $title,
			'header' => $h, 'table' => $list,
		];
	}

	public function linkedDocuments ()
	{
		parent::linkedDocuments ();

		$testNewBankDocDetail = $this->app()->cfgItem ('options.experimental.testNewBankDocDetail', 0);
		if ($testNewBankDocDetail)
			unset($this->content['body'][0]);
	}
}

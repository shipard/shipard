<?php

namespace e10doc\accBal\libs\dc;


/**
 * class DCAccBalance
 */
class DCAccBalance extends \Shipard\Base\DocumentCard
{
	var $accounts = [];
	/** @var \e10doc\accBal\TableBalancesAccounts */
	var $tableBalancesAccounts = NULL;

	protected function addDetail()
	{
		$this->addAccounts();
	}

	protected function addAccounts()
  {
    $q = [];
    array_push($q, 'SELECT [ba].*');
		array_push($q, ' FROM [e10doc_accBal_balancesAccounts] AS [ba]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [ba].[balance] = %i', $this->recData['ndx']);

    //array_push($q, ' AND [ba].[docState] IN %in', [4000, 8000, 9000]);
		//array_push($q, ' AND ([ba].[validFrom] IS NULL OR [ba].[validFrom] <= %d)', $this->forDate);
		//array_push($q, ' AND ([ba].[validTo] IS NULL OR [ba].[validTo] >= %d)', $this->forDate);
    array_push($q, ' ORDER BY [ba].[systemOrder], [ba].[accountId], [ba].[ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $item = [
        'accountId' => $r['accountId'],
        //'accSide' => $r['accSide'],
        //'balSide' => $r['balSide'],
        //'balModifySign' => $r['balModifySign'],
        'amountsSign' => $r['accAmountsSign'],
      ];


			$accSide = $this->tableBalancesAccounts->columnInfoEnum ('accSide', 'cfgText');
			$item ['accSide'] = $accSide[$r['accSide']];

			$balSide = $this->tableBalancesAccounts->columnInfoEnum ('balSide', 'cfgText');
			$item ['balSide'] = ['text' => $balSide[$r['balSide']]];
			if ($r['balModifySign'] == 1)
				$item ['balSide']['suffix'] = ' * -1';

			$accAmountsSign = $this->tableBalancesAccounts->columnInfoEnum ('accAmountsSign', 'cfgText');
			$item ['accSign'] = $accAmountsSign[$r['accAmountsSign']];

      $this->accounts[] = $item;
    }

		$h = [
			'#' => '#',
			'accountId' => 'Účet',
			'accSide' => 'Strana',
			'accSign' => 'Částky',
			'balSide' => 'P/Ú',
			'note' => 'Poznámka',
		];

		$this->addContent([
      'pane' => 'e10-pane e10-pane-table',
      'header' => $h,
      'table' => $this->accounts,
      'title' => 'Nastavení účtů', '_params' => ['hideHeader' => 1],
    ]);

  }


	public function createContentBody ()
	{
		$this->addDetail();
	}

	public function createContent ()
	{
		$this->tableBalancesAccounts = $this->app()->table('e10doc.accBal.balancesAccounts');
		$this->createContentBody ();
	}
}


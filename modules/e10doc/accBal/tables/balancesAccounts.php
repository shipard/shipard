<?php

namespace e10doc\accBal;

use \Shipard\Viewer\TableViewGrid, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TableBalancesAccounts
 */
class TableBalancesAccounts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.accBal.balancesAccounts', 'e10doc_accBal_balancesAccounts', 'Účty saldokont');
	}

	public function checkBeforeSave(&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		$recData['systemOrder']  = 50 - strlen($recData['accountId']);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

//		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
//		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];

		return $hdr;
	}
}


/**
 * class ViewBalancesAccounts
 */
class ViewBalancesAccounts extends TableViewGrid
{
	public function init ()
	{
		parent::init();

		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$g = [
			'bal' => 'Saldokonto',
			'accountId' => 'Účet',
			'accSide' => 'Strana',
			'accSign' => 'Částky',
			'balSide' => 'P/Ú',
			'note' => 'Poznámka',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['accountId'] = $item['accountId'];
		$listItem ['bal'] = $item['balanceFullName'];

		$accSide = $this->table->columnInfoEnum ('accSide', 'cfgText');
		$listItem ['accSide'] = $accSide[$item['accSide']];

		$balSide = $this->table->columnInfoEnum ('balSide', 'cfgText');
		$listItem ['balSide'] = ['text' => $balSide[$item['balSide']]];
		if ($item['balModifySign'] == 1)
			$listItem ['balSide']['suffix'] = ' * -1';

		$accAmountsSign = $this->table->columnInfoEnum ('accAmountsSign', 'cfgText');
		$listItem ['accSign'] = $accAmountsSign[$item['accAmountsSign']];
		$listItem ['note'] = $item['note'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [ba].* ');
		array_push ($q, ', [balances].[fullName] AS [balanceFullName]');
		array_push ($q, ' FROM [e10doc_accBal_balancesAccounts] AS [ba]');
		array_push ($q, ' LEFT JOIN [e10doc_accBal_balances] AS [balances] ON [ba].[balance] = [balances].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [ba].[accountId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [ba].[note] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [balances].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[ba].', ['[balances].[order]', '[balances].[fullName]', '[ba].[systemOrder]', '[accountId]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormBalanceAccount
 */
class FormBalanceAccount extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Účet', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('balance');
					$this->addColumnInput ('accountId');
					$this->addColumnInput ('accSide');
					$this->addColumnInput ('accAmountsSign');
					$this->addColumnInput ('balSide');
					$this->addColumnInput ('balModifySign');
					$this->addSeparator(self::coH3);
          $this->addColumnInput ('note');
					$this->addSeparator(self::coH3);
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailBalanceAccount
 */
class ViewDetailBalanceAccount extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

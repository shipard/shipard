<?php

namespace e10doc\base;

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableBankAccountsGroups
 */
class TableBankAccountsGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.bankAccountsGroups', 'e10doc_base_bankAccountsGroups', 'Skupiny Vlastních bankovních spojení');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['bankAccount']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$bankAccountsGroups = [];
		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_bankAccountsGroups] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'],
				'icon' => ($r['icon'] !== '') ? $r['icon'] : 'iconFolder',
        'accounts' => [],
			];

      $accounts = $this->app()->db->query ('SELECT ndx from [e10doc_base_bankaccounts] WHERE [docState] != ', 9800,
                    ' AND bankAccountsGroup = %i', $r['ndx'], ' ORDER BY [order], [id]');
      foreach ($accounts as $a)
        $item['accounts'][] = $a['ndx'];

			$bankAccountsGroups[$r['ndx']] = $item;
		}

		// -- save to file
		$cfg ['e10doc']['bankAccountsGroups'] = $bankAccountsGroups;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.bankAccountsGroups.json', Utils::json_lint (json_encode ($cfg)));
  }
}


/**
 * class ViewBankAccountsGroups
 */
class ViewBankAccountsGroups extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_bankAccountsGroups]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = $item['id'];
		//$listItem ['t2'] = $item['bankAccount'];
		//$listItem ['i2'] = $item['iban'];
		$listItem ['icon'] = $this->table->tableIcon($item);

		$props = [];
    /*
		if ($item['downloadStatements'] !== '' && $item['downloadStatements'] !== 'none')
		{
			$ds = $this->app()->cfgItem('ebanking.downloads.'.$item['downloadStatements'], FALSE);
			if ($ds)
				$props[] = ['icon' => 'system/actionDownload', 'text' => $ds['title']];
		}
		if ($item['uploadStatements'] !== '' && $item['uploadStatements'] !== 'none')
		{
			$ds = $this->app()->cfgItem('ebanking.uploads.'.$item['uploadStatements'], FALSE);
			if ($ds)
				$props[] = ['icon' => 'system/actionUpload', 'text' => $ds['title']];
		}
		if ($item['downloadTransactions'] !== '' && $item['downloadTransactions'] !== 'none')
		{
			$ds = $this->app()->cfgItem('ebanking.transactions.'.$item['downloadTransactions'], FALSE);
			if ($ds)
				$props[] = ['icon' => 'icon-money', 'text' => $ds['title']];
		}
		if (count($props))
			$listItem ['t3'] = $props;
    */
		return $listItem;
	}
}


/**
 * class FormBankAccountsGroup
 */
class FormBankAccountsGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


<?php

namespace E10Doc\Base;
use \Shipard\Utils\Utils, \Shipard\Utils\Json, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableBankAccounts
 */
class TableBankAccounts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.bankaccounts', 'e10doc_base_bankaccounts', 'Vlastní bankovní spojení');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['bankAccount']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'downloadStatements' || $columnId === 'uploadStatements')
		{
			if ($cfgKey === 'none')
				return TRUE;

			$bankCountry = 'CZ'; // TODO: add country to bankAccount?
			$bankCode = $bankCountry.substr (strstr($form->recData['bankAccount'], '/'), 1);
			if (in_array($bankCode, $cfgItem['availability']))
				return TRUE;

			return FALSE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function saveConfig ()
	{
		// -- default bank account
		$cnt = $this->db()->query ('SELECT COUNT(*) as c FROM [e10doc_base_bankaccounts]')->fetch();
		if ($cnt['c'] == 0)
		{
			$country = $this->app()->cfgItem ('options.core.ownerDomicile');
			$bankAccount = $this->app()->cfgItem ('options.core.ownerBankAccount', '');

			if ($bankAccount != '')
			{
				$iban = new \lib\IBANGenerator ($this->app()->cfgItem ('options.core.ownerBankAccount'), $country);
				$newBankAccount = [
					'fullName' => 'Hlavní bankovní účet',
					'shortName' => 'Hlavní', 'bank' => 0,
					'bankAccount' => $bankAccount, 'iban' => $iban->iban,
					'id' => '1', 'currency' => 'czk',
					'docState' => 4000, 'docStateMain' => 2
				];
				$this->db()->query ('INSERT INTO e10doc_base_bankaccounts ', $newBankAccount);
			}
		}

		// -- create configuration file
		$bankAccounts = array ();
		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_bankaccounts] WHERE [docState] != 9800 ORDER BY [order], [id]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'id' => $r ['id'], 'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'bank' => $r['bank'], 'bankAccount' => $r['bankAccount'],
				'debsAccountId' => isset ($r['debsAccountId']) ? $r['debsAccountId'] : '',
				'curr' => $r['currency'], 'efd' => $r['exclFromDashboard'],
				'group' => $r['bankAccountsGroup'],
				'ds' => $r['downloadStatements'], 'us' => $r['uploadStatements'], 'dt' => $r['downloadTransactions'],
			];

			if ($r['useDownloadStatementBegin'])
			{
				$item ['useDownloadStatementBegin'] = 1;
				$item ['downloadStatementBeginDate'] = $r['downloadStatementBeginDate']->format ('Y-m-d');
				$item ['downloadStatementBeginNumber'] = $r['downloadStatementBeginNumber'];
			}

			$sci = $this->subColumnsInfo ($r, 'options');
			if ($sci)
			{
				$options = Json::decode($r['options']);
				if (!$options)
					$options = [];
				$item['options'] = $options;
			}

			$bankAccounts [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg ['e10doc']['bankAccounts'] = $bankAccounts;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.bankAccounts.json', Utils::json_lint (json_encode ($cfg)));
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'options')
		{
			$bankOptionsId = 'cz';
			$baparts = explode('/', $recData['bankAccount'] ?? '');
			if (count($baparts) === 2)
				$bankOptionsId .= '-'.$baparts[1];

			$optionsFileName = __SHPD_MODULES_DIR__.'e10doc/base/config/ba-options/'.$bankOptionsId.'.json';

			if (is_readable($optionsFileName))
			{
				$options = Utils::loadCfgFile($optionsFileName);
				if (!$options || !isset($options['fields']))
					return FALSE;

				return $options['fields'];
			}
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}
}


/**
 * class ViewBankAccounts
 */
class ViewBankAccounts extends TableView
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

		$q = [];
		array_push ($q, 'SELECT [accounts].*, [accountsGroups].[fullName] AS [accountGroupFullName]');
		array_push ($q, ' FROM [e10doc_base_bankaccounts] AS [accounts]');
		array_push ($q, ' LEFT JOIN [e10doc_base_bankAccountsGroups] AS [accountsGroups] ON [accounts].[bankAccountsGroup] = [accountsGroups].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [accounts].[fullName] LIKE %s', '%'.$fts.'%',
				' OR [accounts].[shortName] LIKE %s', '%'.$fts.'%',
				' OR [accounts].[bankAccount] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[accounts].', ['[accounts].[order]', '[id]', '[accounts].[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];
		$listItem ['t2'] = [['text' => $item['bankAccount'], 'class' => '']];
		$listItem ['i2'] = $item['iban'];
		$listItem ['icon'] = $this->table->tableIcon($item);

		if ($item['bankAccountsGroup'])
			$listItem ['t2'][] = ['text' => $item['accountGroupFullName'], 'class' => 'label label-info', 'icon' => 'iconFolder'];

		$props = [];
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

		return $listItem;
	}
}


/**
 * class FormBankAccounts
 */
class FormBankAccounts extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$sci = $this->table->subColumnsInfo ($this->recData, 'options');

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Ebanking', 'icon' => 'formEBanking'];
			if ($sci)
				$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('bank');
					$this->addColumnInput ('bankAccount');
					$this->addColumnInput ('iban');
					$this->addColumnInput ('swift');
					$this->addColumnInput ('id');
					$this->addColumnInput ('currency');
					$this->addColumnInput ('debsAccountId');
					$this->addColumnInput ('order');
					$this->addColumnInput ('exclFromDashboard');
					$this->addColumnInput ('bankAccountsGroup');
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('ebankingId');
					$this->addSeparator(TableForm::coH2);
						$this->addColumnInput ('downloadStatements');
						$this->addColumnInput ('apiToken');
					$this->addSeparator(TableForm::coH2);
						$this->addColumnInput ('uploadStatements');
						$this->addColumnInput ('apiTokenUploads');
					$this->addSeparator(TableForm::coH2);
						$this->addColumnInput ('downloadTransactions');
						$this->addColumnInput ('apiTokenTransactions');
					$this->addSeparator(TableForm::coH2);
						$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->addSeparator(TableForm::coH2);
						$this->addColumnInput ('useDownloadStatementBegin');
						if ($this->recData['useDownloadStatementBegin'])
						{
							$this->addColumnInput('downloadStatementBeginDate');
							$this->addColumnInput('downloadStatementBeginNumber');
						}
				$this->closeTab();
				if ($sci)
				{
					$this->openTab ();
						$this->addSubColumns ('options');
					$this->closeTab();
				}
			$this->closeTabs();
		$this->closeForm ();
	}
}


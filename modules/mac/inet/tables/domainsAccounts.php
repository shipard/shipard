<?php

namespace mac\inet;


use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\utils;


/**
 * Class TableDomainsAccounts
 */
class TableDomainsAccounts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.inet.domainsAccounts', 'mac_inet_domainsAccounts', 'Doménové účty');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name'] ?? ''];

		return $hdr;
	}

	public function apiClient ($accountNdx)
	{
		$client = NULL;

		$accountRecData = $this->loadItem($accountNdx);
		if (!$accountRecData)
		{
			echo "account not found\n";
			return NULL;
		}

		$registrarCfg = $this->app()->cfgItem ('mac.inet.domainsRegistrars.'.$accountRecData['registrar'], NULL);
		if (!$registrarCfg)
		{
			error_log ("registrarCfg not found: `".'mac.inet.domainsRegistrars.'.$accountRecData['registrar']."`");
			return NULL;
		}

		if (!isset($registrarCfg['apiObjectClass']) || $registrarCfg['apiObjectClass'] === '')
			return NULL;

		$client = $this->app()->createObject ($registrarCfg['apiObjectClass']);

		if ($registrarCfg['type'] == 0)
		{
			$client->auth['login'] = $accountRecData['authLogin'];
			$client->auth['password'] = $accountRecData['authPassword'];
		}
		elseif ($registrarCfg['type'] == 1)
		{
			$intService = $this->db()->query('SELECT * FROM [integrations_core_services] WHERE [ndx] = %i', $accountRecData['intService'])->fetch();
			if (!$intService)
				return NULL;
			$client->auth['integrationService'] = $intService->toArray();
			$client->auth['projectId'] = $accountRecData['projectId'];
		}

		return $client;
	}
}


/**
 * Class ViewDomainsAccounts
 */
class ViewDomainsAccounts extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q[] = 'SELECT * FROM [mac_inet_domainsAccounts] ';

		array_push($q, ' WHERE 1');

		if ($fts != '')
			array_push ($q, ' AND ([name] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		return $listItem;
	}
}


/**
 * Class ViewDetailDomainAccount
 */
class ViewDetailDomainAccount extends TableViewDetail
{
}


/**
 * Class FormDomainAccount
 */
class FormDomainAccount extends TableForm
{
	public function renderForm ()
	{
		$registrarCfg = $this->app()->cfgItem('mac.inet.domainsRegistrars.'.$this->recData['registrar'], NULL);

		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-properties'];
			$tabs ['tabs'][] = ['text' => 'Přihlášení', 'icon' => 'system/actionLogIn'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('registrar');
					$this->addColumnInput ('owner');
				$this->closeTab ();
				$this->openTab ();
					if ($registrarCfg && $registrarCfg['type'] == 0)
					{
						$this->addColumnInput('authLogin');
						$this->addColumnInput('authPassword');
					}
					elseif ($registrarCfg && $registrarCfg['type'] == 1)
					{
						$this->addColumnInput('intService');
						$this->addColumnInput('projectId');
					}
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
		$this->closeTabs ();
		$this->closeForm ();
	}
}



<?php

namespace e10pro\hosting\server;


use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\TableViewPanel, \e10\utils;


/**
 * Class TableDomains
 * @package e10pro\hosting\server
 */
class TableDomains extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.server.domains', 'e10pro_hosting_server_domains', 'Domény');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData['domainAscii'] = idn_to_ascii($recData['domain']);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['domain']];

		return $hdr;
	}
}


/**
 * Class ViewDomains
 * @package e10pro\hosting\server
 */
class ViewDomains extends TableView
{
	/** @var \e10\persons\TablePersons */
	var $tablePersons;
	var $classification;

	public function init ()
	{
		parent::init();
		$this->setMainQueries();

		$this->setPanels (TableView::sptQuery);

		$this->tablePersons = $this->app()->table('e10.persons.persons');
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT domains.*, owners.fullName as ownerFullName, owners.company, owners.personType, owners.gender,';
		array_push($q, ' accountsReg.name AS accountRegName, accountsDNS.name AS accountDNSName');
		array_push($q, ' FROM [e10pro_hosting_server_domains] AS [domains]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [owners] ON domains.owner = owners.ndx');
		array_push($q, ' LEFT JOIN [e10pro_hosting_server_domainsAccounts] AS [accountsReg] ON domains.domainAccount = accountsReg.ndx');
		array_push($q, ' LEFT JOIN [e10pro_hosting_server_domainsAccounts] AS [accountsDNS] ON domains.domainAccountDNS = accountsDNS.ndx');
		array_push($q, ' WHERE 1');

		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [domain] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR [domainAscii] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR owners.fullName LIKE %s', '%' . $fts . '%');

			array_push($q, ')');

		}

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{ // -- tags
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE domains.ndx = recid AND tableId = %s', 'e10pro.hosting.server.domains');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		// -- others - with changes
		$withChanges = isset ($qv['others']['withChanges']);
		if ($withChanges)
			array_push($q, ' AND EXISTS (SELECT ndx FROM e10pro_hosting_server_domainsRecords WHERE domains.ndx = domain AND (versionProvider != versionData OR registrarId = 0))');

		$this->queryMain ($q, 'domains.', ['[domain]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['domain'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['i2'] = utils::datef($item['dateExpire'], '%d');

		$props = [];
		if ($item['ownerFullName'])
			$props[] = ['text' => $item['ownerFullName'], 'icon' => $this->tablePersons->tableIcon ($item), 'class' => 'label label-default'];

		$listItem['t2'] = $props;

		if ($item['domain'] !== $item['domainAscii'])
			$listItem ['t3'][] = ['text' => $item['domainAscii'], 'class' => 'label label-default', 'icon' => 'icon-keyboard-o'];

		if ($item['accountRegName'])
			$listItem ['t3'][] = ['text' => $item['accountRegName'], 'class' => 'label label-default', 'icon' => 'icon-wrench', 'prefix' => 'reg'];
		if ($item['accountDNSName'])
			$listItem ['t3'][] = ['text' => $item['accountDNSName'], 'class' => 'label label-default', 'icon' => 'icon-wrench', 'prefix' => 'dns'];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		// -- show changes
		/*
		$changesEngine = new \e10pro\hosting\server\libs\DomainsChangesEngine($this->app());
		$changesEngine->init();
		$changesEngine->loadChanges();
		if ($changesEngine->changesTable && count($changesEngine->changesTable))
		{
			$changesTitle = [
				['text' => 'Změny v DNS záznamech', 'class' => 'h1'],
				[
					'type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10pro.hosting.server.domains', 'data-class' => 'e10pro.hosting.server.libs.DomainSendChangesWizard',
					'text' => 'Odeslat změny', 'icon' => 'icon-send', 'class' => 'btn-sm pull-right',
					'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => 'default'
				]
			];
			$panel->addContent([
				'type' => 'table', 'pane' => 'e10-pane e10-pane-table',
				'table' => $changesEngine->changesTable, 'header' => $changesEngine->changesHeader,
				'title' => $changesTitle
			]);
		}
		*/

		$qry = [];

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $params];
		}

		// -- others
		/*
		$chbxOthers = [
			'withChanges' => ['title' => 'Obsahuje změnu v záznamech', 'id' => 'withChanges'],
		];
		$paramsOthers = new \E10\Params ($this->app());
		$paramsOthers->addParam ('checkboxes', 'query.others', ['items' => $chbxOthers]);
		$qry[] = ['id' => 'errors', 'style' => 'params', 'title' => 'Ostatní', 'params' => $paramsOthers];
		*/

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewDetailDomain
 * @package e10pro\hosting\server
 */
class ViewDetailDomain extends TableViewDetail
{
}


/**
 * Class ViewDetailDomainRecords
 * @package e10pro\hosting\server
 */
class ViewDetailDomainRecords extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent (
			[
				'type' => 'viewer', 'table' => 'e10pro.hosting.server.domainsRecords', 'viewer' => 'e10pro.hosting.server.ViewDomainsRecords',
				'params' => ['domain' => $this->item ['ndx']]
			]);
	}
}


/**
 * Class ViewDetailDomainAPI
 * @package e10pro\hosting\server
 */
class ViewDetailDomainAPI extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.hosting.server.dc.DomainApi');
	}
}



/**
 * Class FormDomain
 * @package e10pro\hosting\server
 */
class FormDomain extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-properties'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('domain');
					$this->addColumnInput ('owner');
					$this->addColumnInput ('domainAccount');
					$this->addColumnInput ('domainAccountDNS');
					$this->addColumnInput ('dateExpiry');
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

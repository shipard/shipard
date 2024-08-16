<?php

namespace mac\inet;


use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\utils;


/**
 * Class TableDomainsRecords
 */
class TableDomainsRecords extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.inet.domainsRecords', 'mac_inet_domainsRecords', 'Doménové záznamy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['hostName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['value']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$id = $recData['hostName'].'-'.($recData['value'] ?? $recData['valueMemo'] ?? '').'-'.$recData['priority'].'-'.$recData['ttl'];
		$recData['versionData'] = sha1($id);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData ['registrarId'] = 0;
		$recData ['versionProvider'] = '';
		$recData ['versionData'] = '';

		return $recData;
	}
}


/**
 * Class ViewDomainsRecords
 */
class ViewDomainsRecords extends TableView
{
	var $domain = 0;
	var $domainRecordTypes;

	public function init ()
	{
		parent::init();
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries();

		$this->domainRecordTypes = $this->app()->cfgItem('mac.inet.domainsRecordTypes');

		if ($this->queryParam ('domain'))
		{
			$this->domain = intval($this->queryParam('domain'));
			$this->addAddParam ('domain', $this->domain);
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT * FROM [mac_inet_domainsRecords] ';

		array_push($q, ' WHERE 1');

		if ($this->domain)
			array_push ($q, ' AND [domain] = %i', $this->domain);

		if ($fts != '')
			array_push ($q, ' AND ([hostName] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[displayOrder]', '[hostName]', '[value]', '[priority]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['hostName'];

		if (!$item['registrarId'])
			$listItem['class'] = 'e10-row-info';
		else
		if ($item['versionProvider'] !== $item['versionData'] || $item['versionData'] === '' || $item['versionProvider'] === '')
			$listItem['class'] = 'e10-row-minus';

		if ($listItem ['t1'] === '')
			$listItem ['t1'] = ['text' => '@', 'class' => 'e10-off'];

		$props = [];
		$props[] = ['text' => $this->domainRecordTypes[$item['recordType']]['name'], 'class' => 'label label-info'];
		$props[] = ['text' => $item['value'], 'class' => ''];
		if ($item['priority'])
			$props[] = ['text' => utils::nf($item['priority']), 'class' => 'label label-default', 'prefix' => 'pri'];
		if ($item['ttl'])
			$props[] = ['text' => utils::nf($item['ttl']), 'class' => 'label label-default', 'prefix' => 'ttl'];
		$listItem ['t2'] = $props;

		$listItem ['i2'] = ['text' => '#'.utils::nf($item['registrarId']), 'class' => 'id'];

		return $listItem;
	}
}


/**
 * Class ViewDetailDomainRecord
 */
class ViewDetailDomainRecord extends TableViewDetail
{
}


/**
 * Class FormDomainRecord
 */
class FormDomainRecord extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-properties'];
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('recordType');
					$this->addColumnInput ('hostName');
					$this->addColumnInput ('value');
					$this->addColumnInput ('priority');
					$this->addColumnInput ('ttl');
					$this->addColumnInput ('domain');
					$this->addColumnInput ('registrarId');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


<?php

namespace hosting\core;


use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \e10\TableForm, \e10\DbTable, \Shipard\Viewer\TableViewPanel, \e10\utils;
use \e10\base\libs\UtilsBase;

/**
 * Class TableCerts
 */
class TableCerts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.certs', 'hosting_core_certs', 'Certifikáty');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData['hostAscii'] = idn_to_ascii($recData['host']);

		$recData['anotherHostsAscii'] = '';
		$ah = explode (' ', $recData['anotherHosts']);
		$ahAscii = [];
		foreach ($ah as $ahOne)
		{
			$ahOneTrim = trim($ahOne);
			if ($ahOneTrim === '')
				continue;
			$ahAscii[] = idn_to_ascii($ahOneTrim);
		}
		if (count($ahAscii))
			$recData['anotherHostsAscii'] = implode(' ', $ahAscii);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['newMode'] = 1;
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['host']];

		$hosts = [];
		$hosts[] = ['text' => $recData ['hostAscii'], 'class' => 'label label-default'];
		$hns = explode (' ', $recData['anotherHostsAscii']);
		foreach ($hns as $hnsOne)
			$hosts[] = ['text' => $hnsOne, 'class' => 'label label-default'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $hosts];

		return $hdr;
	}
}


/**
 * Class ViewCerts
 */
class ViewCerts extends TableView
{
	var $classification;
	var $certsProviders;

	public function init ()
	{
		parent::init();
		$this->setMainQueries();

		$this->certsProviders = $this->app()->cfgItem('hosting.core.certsProviders');

		$this->setPanels (TableView::sptQuery);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT certs.*, ds.name AS dsName, ds.gid as dsGid';
		array_push($q, ' FROM [hosting_core_certs] AS [certs]');
		array_push($q, ' LEFT JOIN [hosting_core_dataSources] AS [ds] ON certs.dataSource = ds.ndx');
		array_push($q, ' WHERE 1');

		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [host] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR [hostAscii] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR [anotherHosts] LIKE %s', '%' . $fts . '%');
			array_push($q, ')');
		}

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{ // -- tags
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE certs.ndx = recid AND tableId = %s', 'hosting.core.certs');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}


		$this->queryMain ($q, 'certs.', ['[host]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);

		$listItem ['t1'] = ['text' => $item['host']];
		if ($item['anotherHosts'] !== '')
			$listItem ['t1']['suffix'] = $item['anotherHosts'];

		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['i2'] = utils::datef($item['dateExpire'], '%d');

		$props = [];
		if ($item['dsName'])
			$props[] = ['text' => $item['dsName'], 'suffix' => '#'.$item['dsGid'], 'icon' => 'system/iconDatabase', 'class' => 'label label-default'];
		$listItem['t2'] = $props;

		$cp = $this->certsProviders[$item['provider']];
		$listItem['i2'] = ['text' => $cp['name'], 'icon' => 'icon-lock', 'class' => 'label label-'.$cp['labelClass']];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			$item ['t3'] = [];
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);
		}
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewDetailCert
 */
class ViewDetailCert extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('hosting.core.libs.dc.DocumentCardCert');
	}
}


/**
 * Class FormCert
 */
class FormCert extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Certifikát', 'icon' => 'icon-certificate'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('host');
					$this->addColumnInput ('anotherHosts');
					$this->addColumnInput ('fileId');
					$this->addColumnInput ('provider');
					$this->addColumnInput ('dataSource');
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

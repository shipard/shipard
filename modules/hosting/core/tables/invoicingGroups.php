<?php

namespace hosting\core;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableInvoicingGroups
 */
class TableInvoicingGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.invoicingGroups', 'hosting_core_invoicingGroups', 'Fakturační skupiny');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['info'][] = ['class' => 'info', 'value' => [['text' => $recData ['gid'], 'class' => 'pull-right']]];
		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['name']]]];

		return $hdr;
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$title = $recData['name'];
		$info = [
			'title' => $title, 'docID' => $recData['gid']
		];

		$info ['persons']['to'][] = $recData['payer'];
		$info ['persons']['from'][] = intval($this->app()->cfgItem ('options.core.ownerPerson', 0));

		return $info;
	}
}


/**
 * Class ViewInvoicingGroups
 */
class ViewInvoicingGroups extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries();
		$this->linesWidth = 30;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT [invg].*, [payers].[fullName] AS [payerFullName]';
		array_push($q, ' FROM [hosting_core_invoicingGroups] AS [invg]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [payers] ON [invg].[payer] = [payers].[ndx]');
		array_push($q, ' WHERE 1');

		if ($fts != '')
			array_push ($q, ' AND ([invg].[name] LIKE %s OR [payers].[fullName] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '[invg].', ['[invg].[name]', '[invg].[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['name'];

		$listItem ['t2'] = [['text' => $item['payerFullName'], 'icon' => 'user/piggyBank', 'class' => '']];

		$listItem ['i1'] = '#'.$item['ndx'];

		return $listItem;
	}
}


/**
 * Class ViewDetailInvoicingGroup
 */
class ViewDetailInvoicingGroup extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('hosting.core.libs.dc.DCInvoicingGroup');
	}
}


/**
 * Class FormPartner
 */
class FormInvoicingGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('partner');
					$this->addColumnInput ('payer');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('gid');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

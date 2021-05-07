<?php

namespace e10pro\hosting\server;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \e10\utils, \E10\DbTable;


/**
 * Class TableSupportsKinds
 * @package e10pro\hosting\server
 */
class TableSupportsKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.server.supportsKinds', 'e10pro_hosting_server_supportsKinds', 'Druhy podpory');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		//$hdr ['info'][] = ['class' => 'info', 'value' => [['text'=>$recData ['domain']]]];
		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['name']]]];

		return $hdr;
	}
}


/**
 * Class ViewSupportsKinds
 * @package e10pro\hosting\server
 */
class ViewSupportsKinds extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT [sk].*';
		array_push($q, ' FROM [e10pro_hosting_server_supportsKinds] AS [sk]');
		array_push($q, ' WHERE 1');

		if ($fts != '')
			array_push ($q, ' AND ([sk].[name] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, '[sk].', ['[sk].[name]', '[sk].[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = [['text' => $item['name'], 'class' => ''], ];

		$pt = $this->table->columnInfoEnum ('forumLevel', 'cfgText');

		$listItem ['t2'] = [];
		$listItem ['t2'][] = ['text' => $pt [$item ['forumLevel']], 'icon' => 'icon-bullhorn', 'class' => 'label label-default'];

		return $listItem;
	}
}


/**
 * Class ViewDetailSupportKind
 * @package e10pro\hosting\server
 */
class ViewDetailSupportKind extends TableViewDetail
{
}


/**
 * Class FormSupportsKind
 * @package e10pro\hosting\server
 */
class FormSupportsKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Podpora', 'icon' => 'icon-umbrella'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('forumLevel');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


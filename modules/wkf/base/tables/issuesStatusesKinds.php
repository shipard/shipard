<?php

namespace wkf\base;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableIssuesStatusesKinds
 * @package wkf\base
 */
class TableIssuesStatusesKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.issuesStatusesKinds', 'wkf_base_issuesStatusesKinds', 'Druhy statusů Zpráv');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewIssuesStatusesKinds
 * @package wkf\base
 */
class ViewIssuesStatusesKinds extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		if ($item['fullName'] === $item['shortName'])
		{
			$listItem ['t1'] = $item['fullName'];
		}
		else
		{
			$listItem ['t1'] = ['text' => $item['fullName'], 'suffix' => $item['shortName']];
		}


		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'icon-sort', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = intval($this->bottomTabId ());

		$q [] = 'SELECT issuesStatusesKinds.*';
		array_push ($q, ' FROM [wkf_base_issuesStatusesKinds] AS [issuesStatusesKinds]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' issuesStatusesKinds.[fullName] LIKE %s', '%'.$fts.'%',
				' OR issuesStatusesKinds.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[issuesStatusesKinds].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormIssueStatusKind
 * @package wkf\base
 */
class FormIssueStatusKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'x-content'];
		//$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'x-wrench'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('order');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}

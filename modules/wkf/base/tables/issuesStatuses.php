<?php

namespace wkf\base;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableIssuesStatuses
 * @package wkf\base
 */
class TableIssuesStatuses extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.issuesStatuses', 'wkf_base_issuesStatuses', 'Statusy Zpráv', 1244);
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
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewIssuesStatuses
 * @package wkf\base
 */
class ViewIssuesStatuses extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$rows = $this->app()->db->query ('SELECT * FROM [wkf_base_issuesStatusesKinds] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		$bt = [];
		$active = 1;
		foreach ($rows as $r)
		{
			$addParams = ['statusKind' => $r['ndx']];
			$bt [] = ['id' => $r['ndx'], 'title' => $r['shortName'], 'active' => $active, 'addParams' => $addParams];
			$active = 0;
		}
		$bt[] = ['id' => '0', 'title' => 'Vše', 'active' => 0];
		$this->setBottomTabs ($bt);

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

		$lc = $this->table->columnInfoEnum ('lifeCycle');
		$listItem ['t2'] = [['text' => $lc[$item['lifeCycle']], 'icon' => 'icon-heartbeat', 'class' => 'label label-default']];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		$bottomTabId = intval($this->bottomTabId ());
		if (!$bottomTabId)
		{
			if ($item['statusName'])
				$listItem ['t2'][] = ['text' => $item['statusName'], 'icon' => 'icon-columns', 'class' => 'label label-default'];
			else
				$listItem ['t2'][] = ['text' => 'Není zadán Druh statusu', 'icon' => 'system/iconWarning', 'class' => 'label label-danger'];
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = intval($this->bottomTabId ());

		$q [] = 'SELECT issuesStatuses.*, statusesKinds.shortName AS statusName ';
		array_push ($q, ' FROM [wkf_base_issuesStatuses] AS [issuesStatuses]');
		array_push ($q, ' LEFT JOIN [wkf_base_issuesStatusesKinds] AS [statusesKinds] ON issuesStatuses.statusKind = statusesKinds.ndx');
		array_push ($q, ' WHERE 1');

		if ($bottomTabId !== 0)
			array_push ($q, ' AND [statusKind] = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' issuesStatuses.[fullName] LIKE %s', '%'.$fts.'%',
				' OR issuesStatuses.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[issuesStatuses].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormIssueStatus
 * @package wkf\base
 */
class FormIssueStatus extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		//$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('section');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('lifeCycle');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('order');
					$this->addColumnInput ('statusKind');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}

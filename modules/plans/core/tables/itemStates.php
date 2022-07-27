<?php

namespace plans\core;

use \e10\TableForm, \e10\DbTable, \e10\TableView, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableItemStates
 */
class TableItemStates extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('plans.core.itemStates', 'plans_core_itemStates', 'Stavy plánování');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['shortName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [plans_core_itemStates] WHERE docState != 9800 ORDER BY [order], [fullName]");
		$states = [];
		$lifeCycles = [];
		foreach ($rows as $r)
		{
			$lc = $r['lifeCycle'];
			$lcCfg = $this->app()->cfgItem('plans.itemStatesLifeCycle.'.$lc, NULL);
			$icon = $r['icon'];
			if ($icon === '' && $lcCfg)
				$icon = $lcCfg['icon'];
			$state = [
				'ndx' => $r['ndx'], 'fn' => $r['fullName'], 'sn' => $r['shortName'],
				'lifeCycle' => $r['lifeCycle'],
				'title' => $r['title'],
    		'icon' => $icon,

				'colorbg' => $r['colorbg'], 'colorfg' => $r['colorfg'],
			];

			if (!isset($lifeCycles[$lc]))
				$lifeCycles[$lc] = [];
			$lifeCycles[$lc][] = $r['ndx'];

			$states [$r['ndx']] = $state;
		}

		$cfg ['plans']['itemStates'] = $states;
		$cfg ['plans']['lifeCycleItemStates'] = $lifeCycles;
		file_put_contents(__APP_DIR__ . '/config/_plans.itemStates.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewPlans
 */
class ViewItemStates extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		//$listItem ['t2'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [plans_core_itemStates]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormItemState
 */
class FormItemState extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('order');
					$this->addColumnInput ('lifeCycle');

					$this->addColumnInput ('icon');
					$this->addColumnInput ('colorbg');
					$this->addColumnInput ('colorfg');
					$this->addColumnInput ('plan');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailItemState
 */
class ViewDetailItemState extends TableViewDetail
{
}


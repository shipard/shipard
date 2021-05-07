<?php

namespace wkf\base;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableTargets
 * @package wkf\base
 */
class TableTargets extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.targets', 'wkf_base_targets', 'Cíle', 1245);
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
 * Class ViewTargets
 * @package wkf\base
 */
class ViewTargets extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$rows = $this->app()->db->query ('SELECT * FROM [wkf_base_targetsKinds] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		$bt = [];
		$active = 1;
		foreach ($rows as $r)
		{
			$addParams = ['targetKind' => $r['ndx']];
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
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($item['fullName'] === $item['shortName'])
		{
			$listItem ['t1'] = $item['fullName'];
		}
		else
		{
			$listItem ['t1'] = ['text' => $item['fullName'], 'suffix' => $item['shortName']];
		}

		$bottomTabId = intval($this->bottomTabId ());
		if (!$bottomTabId)
		{
			if ($item['targetKindName'])
				$listItem ['t2'] = ['text' => $item['targetKindName'], 'icon' => 'icon-columns', 'class' => 'label label-default'];
			else
				$listItem ['t2'] = ['text' => 'Není zadán druh cíle', 'icon' => 'icon-exclamation-triangle', 'class' => 'label label-danger'];
		}

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

		$q [] = 'SELECT targets.*, targetsKinds.shortName AS targetKindName';
		array_push ($q, ' FROM [wkf_base_targets] AS [targets]');
		array_push ($q, ' LEFT JOIN [wkf_base_targetsKinds] AS [targetsKinds] ON targets.targetKind = targetsKinds.ndx');
		array_push ($q, ' WHERE 1');

		if ($bottomTabId !== 0)
			array_push ($q, ' AND [targetKind] = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' targets.[fullName] LIKE %s', '%'.$fts.'%',
				' OR targets.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[targets].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormTarget
 * @package wkf\base
 */
class FormTarget extends TableForm
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
					$this->addColumnInput ('targetKind');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('order');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}

<?php

namespace swdev\dm;


use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Viewer\TableViewPanel, \E10\DbTable, \E10\utils;
use \e10\base\libs\UtilsBase;

/**
 * Class TableModules
 * @package swdev\dm
 */
class TableModules extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.modules', 'swdev_dm_modules', 'Moduly');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}
}


/**
 * Class ViewModules
 * @package swdev\dm
 */
class ViewModules extends TableView
{
	var $osInfo = [];
	var $deviceInfo = [];

	public function init ()
	{
		parent::init();

		$this->setMainQueries ();

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = $item['id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT mdls.*';

		array_push ($q, ' FROM [swdev_dm_modules] AS mdls');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'(mdls.[name] LIKE %s', '%'.$fts.'%',
				' OR mdls.[id] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		// -- special queries
		/*
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE devices.ndx = recid AND tableId = %s', 'mac.lan.devices');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		if (isset ($qv['kinds']))
			array_push ($q, " AND devices.[deviceKind] IN %in", array_keys($qv['kinds']));

		if (isset ($qv['lans']))
			array_push ($q, " AND devices.[lan] IN %in", array_keys($qv['lans']));

		// -- os version
		if (isset($qv['os']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM mac_lan_devicesProperties WHERE devices.ndx = device');
			array_push ($q, ' AND ([property] = %i', 100, ' AND [key2] IN %in', array_keys($qv['os']), ')');
			array_push ($q, ')');
		}

		// -- applications
		if (isset($qv['apps']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM mac_lan_devicesProperties WHERE devices.ndx = device');
			array_push ($q, ' AND ([property] = %i', 3, ' AND [i2] IN %in', array_keys($qv['apps']), ' AND [deleted] = 0', ')');
			array_push ($q, ')');
		}
		*/

		$this->queryMain ($q, 'mdls.', ['mdls.[id]', 'mdls.[ndx]']);

		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormModule
 * @package swdev\dm
 */
class FormModule extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('id');
					$this->addColumnInput ('name');
					$this->addColumnInput ('srcLanguage');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailModule
 * @package swdev\dm
 */
class ViewDetailModule extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

<?php

namespace swdev\dm;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Viewer\TableViewPanel, \E10\DbTable, \E10\utils;
use \e10\base\libs\UtilsBase;

/**
 * Class TableColumns
 * @package swdev\dm
 */
class TableColumns extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.columns', 'swdev_dm_columns', 'Sloupce', 1301);
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
 * Class ViewColumns
 * @package swdev\dm
 */
class ViewColumns extends TableView
{
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
		$listItem ['i1'] = ['text' => '#'.utils::nf($item['ndx']), 'class' => 'id'];
		$listItem ['t2'] = $item['id'];
		$listItem ['i2'] = ['text' => $item['tableName'], 'icon' => 'icon-table', 'suffix' => '#'.$item['table']];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [cols].*, [tbls].[name] AS [tableName]';

		array_push ($q, ' FROM [swdev_dm_columns] AS [cols]');
		array_push ($q, ' LEFT JOIN [swdev_dm_tables] AS [tbls] ON [cols].[table] = [tbls].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([cols].[name] LIKE %s', '%'.$fts.'%',
				' OR [cols].[id] LIKE %s', '%'.$fts.'%',
				' OR [cols].[label] LIKE %s', '%'.$fts.'%',
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

		$this->queryMain ($q, '[cols].', ['[cols].[id]', '[cols].[ndx]']);

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
 * Class FormColumn
 * @package swdev\dm
 */
class FormColumn extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Definice', 'icon' => 'formDefinition'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('table');
					$this->addColumnInput ('id');
					$this->addColumnInput ('name');
					$this->addColumnInput ('label');

					$this->addColumnInput ('colTypeId');
					$this->addColumnInput ('colTypeReferenceId');
					$this->addColumnInput ('colTypeEnumId');
					$this->addColumnInput ('colTypeLen');
					$this->addColumnInput ('colTypeDec');

				$this->closeTab ();
				$this->openTab ();
					$this->addStatic(['type' => 'text', 'text' => $this->recData['jsonDef']]);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailColumn
 * @package swdev\dm
 */
class ViewDetailColumn extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

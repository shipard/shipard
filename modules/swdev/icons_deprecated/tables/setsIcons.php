<?php

namespace swdev\icons;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableSetsIcons
 * @package swdev\icons
 */
class TableSetsIcons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.icons.setsIcons', 'swdev_icons_setsIcons', 'Ikony v Sadě ikon');
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
 * Class ViewSetsIcons
 * @package swdev\icons
 */
class ViewSetsIcons extends TableView
{
	var $classification = NULL;
	var $iconsSet = 0;

	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['svgIcon'] = $this->app()->dsRoot.'/sc/'.$item['pathSvgs'].$item['pvAdmId'].'/'.$item['id'].'.svg';
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		if ($item['setName'])
			$listItem ['i2'] = ['text' => $item['setName'], 'class' => 'label label-info', 'icon' => 'icon-th'];
		else
			$listItem ['i2'] = ['text' => '!!!', 'class' => 'label label-danger', 'icon' => 'system/iconWarning'];

		$listItem ['t2'] = $item['id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [setsIcons].*, [sets].[shortName] AS [setName], [sets].pathSvgs, pvAdm.id AS pvAdmId';

		array_push ($q, ' FROM [swdev_icons_setsIcons] AS [setsIcons]');
		array_push ($q, ' LEFT JOIN [swdev_icons_sets] AS [sets] ON [setsIcons].[iconsSet] = [sets].[ndx]');
		array_push ($q, ' LEFT JOIN [swdev_icons_setsVariants] AS [pvAdm] ON [sets].[primaryVariantAdm] = [pvAdm].[ndx]');
		array_push ($q, ' WHERE 1');

		if ($this->iconsSet !== 0)
			array_push($q, ' AND [setsIcons].iconsSet = %i', $this->iconsSet);

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([setsIcons].[name] LIKE %s', '%'.$fts.'%',
				' OR [setsIcons].[id] LIKE %s', '%'.$fts.'%',
				')'
			);

			array_push ($q, ' OR EXISTS (',
				'SELECT e10_base_clsf.ndx FROM e10_base_clsf ',
				' LEFT JOIN [e10_base_clsfitems] ON e10_base_clsf.clsfItem = e10_base_clsfitems.ndx',
				' WHERE setsIcons.ndx = recid AND tableId = %s', $this->tableId(),
				' AND e10_base_clsfitems.id LIKE %s', '%'.$fts.'%',
			')'
			);
			//array_push ($q, ')');

			array_push($q, ')');
		}

		// -- special queries
		$qv = $this->queryValues ();
		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE setsIcons.ndx = recid AND tableId = %s', $this->tableId());
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}
		if (isset ($qv['sets']))
			array_push ($q, " AND [setsIcons].[iconsSet] IN %in", array_keys($qv['sets']));


		$this->queryMain ($q, '[setsIcons].', ['[setsIcons].[id]', '[setsIcons].[name]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
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

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- sets
		$sets = $this->db()->query ('SELECT ndx, shortName FROM swdev_icons_sets WHERE docStateMain != 4')->fetchPairs ('ndx', 'shortName');
		$sets['0'] = 'Žádná sada';
		$this->qryPanelAddCheckBoxes($panel, $qry, $sets, 'sets', 'Sady ikon');

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $params];
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewSetsIconsCombo
 * @package swdev\icons
 */
class ViewSetsIconsCombo extends ViewSetsIcons
{
	public function init ()
	{
		parent::init();

		$this->iconsSet = intval($this->queryParam('iconsSet'));
	}

	public function selectRows2 ()
	{
	}

	function decorateRow (&$item)
	{
	}
}


/**
 * Class FormSetIcon
 * @package swdev\icons
 */
class FormSetIcon extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Ikona', 'icon' => 'icon-file-image-o'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('iconsSet');
					$this->addColumnInput ('name');
					$this->addColumnInput ('id');
					$this->addList('clsf', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSetIcon
 * @package swdev\icons
 */
class ViewDetailSetIcon extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.icons.dc.SetsIcon');
	}
}

<?php

namespace swdev\dm;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils, e10\str;


/**
 * Class TableTables
 * @package swdev\dataModel
 */
class TableEnums extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.enums', 'swdev_dm_enums', 'Enumy', 1320);
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
 * Class ViewEnums
 * @package swdev\dm
 */
class ViewEnums extends TableView
{
	var $classification;

	public function init ()
	{
		parent::init();
		$this->linesWidth = 33;

		//$this->setMainQueries ();
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];

		$userLanguages = $this->app()->cfgItem('swdev.tr.translators.'.$this->app()->userNdx(), [1]);
		$allLanguages = $this->app()->cfgItem ('swdev.tr.lang.langs', []);

		foreach ($userLanguages as $ul)
		{
			$lang = $allLanguages[$ul];
			$mq [] = ['id' => 'untranslated-'.$ul, 'title' => str::toupper($lang['code']).' '.$lang['flag'], 'side' => 'left'];
		}

		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];

		$this->setMainQueries ($mq);


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

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			if (!isset($item ['i2']))
				$item ['i2'] = [];

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['i2'] = array_merge ($item ['i2'], $clsfGroup);
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mq = $this->mainQueryId ();

		$q [] = 'SELECT enums.*';

		array_push ($q, ' FROM [swdev_dm_enums] AS enums');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'enums.[name] LIKE %s', '%'.$fts.'%',
				' OR enums.[id] LIKE %s', '%'.$fts.'%'
			);

			if (strlen($fts) >= 4 && strval(intval($fts)) === $fts)
				array_push($q, ' OR enums.ndx = %i', intval($fts));

			array_push ($q, ' OR EXISTS (SELECT enum FROM swdev_dm_enumsValues WHERE enums.ndx = swdev_dm_enumsValues.enum',
					' AND swdev_dm_enumsValues.[text] LIKE %s', '%'.$fts.'%', ')');

			array_push($q, ')');
		}

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE enums.ndx = recid AND tableId = %s', 'swdev.dm.enums');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		if (substr($mq, 0, 12) === 'untranslated')
		{
			$mqParts = explode('-', $mq);
			$langNdx = intval($mqParts[1]);
			array_push($q, ' AND [enums].[docStateMain] < %i', 4);
			array_push($q, ' AND (');

			array_push($q, ' [enums].[ndx] IN ',
				'(SELECT  enum FROM `swdev_dm_enumsValues` AS ev',
				' WHERE NOT EXISTS (SELECT enum FROM swdev_dm_enumsValuesTr AS evtr WHERE ev.ndx = evtr.[enumValue] AND [evtr].[lang] = %i) ', $langNdx,
				')');

			array_push($q, ' OR ');

			array_push($q, ' [enums].[ndx] IN ',
				'(SELECT  enum FROM `swdev_dm_enumsValues` AS ev',
				' WHERE EXISTS (SELECT enum FROM swdev_dm_enumsValuesTr AS evtr WHERE ev.ndx = evtr.[enumValue] AND [evtr].[lang] = %i', $langNdx,
				' AND [evtr].docState = %i', 1200, ')',
				')');

			array_push($q, ')');
		}
		elseif ($mq === 'active' || $mq === '')
		{
			array_push($q, ' AND [enums].[docStateMain] < %i', 4);
		}
		elseif ($mq === 'trash')
		{
			array_push($q, ' AND [enums].[docStateMain] = %i', 4);
		}

		array_push($q, ' ORDER BY [enums].[id], [enums].[ndx]');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

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
 * Class FormEnum
 * @package swdev\dm
 */
class FormEnum extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Enum', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'cfg', 'icon' => 'icon-cogs'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('id');
					$this->addColumnInput ('name');
					$this->addColumnInput ('srcLanguage');
					$this->addColumnInput ('dmWikiPage');
					$this->addList('clsf', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab (self::ltNone);
					$this->addInputMemo ('config', NULL, self::coFullSizeY|self::coReadOnly);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailEnum
 * @package swdev\dm
 */
class ViewDetailEnum extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.dm.dc.DMEnum');
	}
}


/**
 * Class ViewDetailEnumTrData
 * @package swdev\dm
 */
class ViewDetailEnumTrData extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.dm.dc.DMEnumTrData');
	}
}


/**
 * Class ViewDetailEnumDoc
 * @package swdev\dm
 */
class ViewDetailEnumDoc extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.dm.dc.DMEnumDoc');
	}
}

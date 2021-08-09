<?php

namespace swdev\dm;


use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \E10\TableForm, \Shipard\Viewer\TableViewPanel, \E10\DbTable, \E10\utils, e10\str;
use \e10\base\libs\UtilsBase;

/**
 * Class TableTables
 * @package swdev\dataModel
 */
class TableTables extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.tables', 'swdev_dm_tables', 'Tabulky', 1188);
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewTables
 * @package swdev\dm
 */
class ViewTables extends TableView
{
	var $classification;

	public function init ()
	{
		parent::init();

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

		$q [] = 'SELECT tbls.*';

		array_push ($q, ' FROM [swdev_dm_tables] AS tbls');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'tbls.[name] LIKE %s', '%'.$fts.'%',
				' OR tbls.[id] LIKE %s', '%'.$fts.'%',
				' OR tbls.[sql] LIKE %s', '%'.$fts.'%'
			);

			if (strlen($fts) >= 4 && strval(intval($fts)) === $fts)
				array_push($q, ' OR tbls.ndx = %i', intval($fts));

			array_push ($q, ' OR EXISTS (SELECT [table] FROM swdev_dm_columns WHERE tbls.ndx = swdev_dm_columns.[table]',
				' AND swdev_dm_columns.[name] LIKE %s', '%'.$fts.'%', ')');

			array_push($q, ')');
		}

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE tbls.ndx = recid AND tableId = %s', 'swdev.dm.tables');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		if (substr($mq, 0, 12) === 'untranslated')
		{
			$mqParts = explode('-', $mq);
			$langNdx = intval($mqParts[1]);
			array_push($q, ' AND [tbls].[docStateMain] < %i', 4);
			array_push($q, ' AND (');
			array_push($q, ' NOT EXISTS (SELECT ndx FROM swdev_dm_dmTrTexts ',
				'WHERE [tbls].ndx = [table] AND [lang] = %i', $langNdx,
				')');
			array_push($q, ' OR EXISTS (SELECT [table] FROM swdev_dm_dmTrTexts ',
				'WHERE [tbls].ndx = [table] AND [lang] = %i', $langNdx,
				' AND swdev_dm_dmTrTexts.docState != %i', [4000],
				' AND [column] IS NOT NULL',
				')');
			array_push($q, ')');
		}
		elseif ($mq === 'active' || $mq === '')
		{
			array_push($q, ' AND [tbls].[docStateMain] < %i', 4);
		}
		elseif ($mq === 'trash')
		{
			array_push($q, ' AND [tbls].[docStateMain] = %i', 4);
		}

		array_push($q, ' ORDER BY [tbls].[id], [tbls].[ndx]');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		// -- lans
//		$lans = $this->db()->query ('SELECT ndx, fullName FROM mac_lan_lans WHERE docStateMain != 4')->fetchPairs ('ndx', 'fullName');
//		$lans['0'] = 'Žádná síť';
//		$this->qryPanelAddCheckBoxes($panel, $qry, $lans, 'lans', 'Sítě');


		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormTable
 * @package swdev\dm
 */
class FormTable extends TableForm
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
					$this->addColumnInput ('sql');
					$this->addColumnInput ('srcLanguage');
					$this->addColumnInput ('icon');
					$this->addList('clsf', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailTable
 * @package swdev\dm
 */
class ViewDetailTable extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.dm.dc.DMTable');
	}
}


/**
 * Class ViewDetailTableTrData
 * @package swdev\dm
 */
class ViewDetailTableTrData extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.dm.dc.DMTableTrData');
	}
}


/**
 * Class ViewDetailTableDoc
 * @package swdev\dm
 */
class ViewDetailTableDoc extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.dm.dc.DMTableDoc');
	}
}


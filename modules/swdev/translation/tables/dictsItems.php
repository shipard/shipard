<?php

namespace swdev\translation;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \e10\utils, \e10\str;


/**
 * Class TableDictsItems
 * @package swdev\translation
 */
class TableDictsItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.translation.dictsItems', 'swdev_translation_dictsItems', 'Položky slovníků');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['identifier']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['description']];

		return $h;
	}
}


/**
 * Class ViewDictsItems
 * @package swdev\translation
 */
class ViewDictsItems extends TableView
{
	/** @var \e10\Params */
	var $dictsParam = NULL;

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

		// -- left panel; dicts
		$dicts = $this->table->app()->cfgItem ('swdev.tr.dicts');
		$this->usePanelLeft = TRUE;
		$this->linesWidth = 45;
		$enum = [];
		forEach ($dicts as $d)
			$enum[$d['ndx']] = ['text' => $d['name'], 'addParams' => ['dict' => $d['ndx']]];
		$this->dictsParam = new \E10\Params ($this->app);
		$this->dictsParam->addParam('switch', 'dict', ['title' => '', 'switch' => $enum, 'list' => 1]);
		$this->dictsParam->detectValues();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];


		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['identifier'];
		$listItem ['t2'] = $item['text'];
		if ($item['description'] !== '')
			$listItem ['t3'] = $item['description'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT dictsItems.*, ';
		array_push ($q, ' worldLanguages.name AS dstLangName, worldLanguages.id AS dstLangId ');
		array_push ($q, ' FROM [swdev_translation_dictsItems] AS [dictsItems]');
		array_push ($q, ' LEFT JOIN [swdev_translation_dicts] AS [dicts] ON dictsItems.dict = dicts.ndx');
		array_push ($q, ' LEFT JOIN swdev_world_languages AS worldLanguages ON dicts.srcLanguage = worldLanguages.ndx');
		array_push ($q, ' WHERE 1');

		// -- dict
		$dictNdx = 0;
		if ($this->dictsParam)
			$dictNdx = intval($this->dictsParam->detectValues()['dict']['value']);
		if ($dictNdx)
			array_push($q, ' AND [dictsItems].[dict] = %i', $dictNdx);

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [dictsItems].[text] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR [dictsItems].[identifier] LIKE %s', '%'.$fts.'%');
			array_push($q, ')');
		}

		$mq = $this->mainQueryId ();

		if (substr($mq, 0, 12) === 'untranslated')
		{
			$mqParts = explode('-', $mq);
			$langNdx = intval($mqParts[1]);
			array_push($q, ' AND [dictsItems].[docStateMain] < %i', 4);
			array_push($q, ' AND (');
			array_push($q, ' NOT EXISTS (SELECT ndx FROM swdev_translation_dictsItemsTr ',
				'WHERE dictsItems.ndx = dictItem AND lang = %i', $langNdx,
				')');
			array_push($q, ' OR EXISTS (SELECT ndx FROM swdev_translation_dictsItemsTr ',
				'WHERE dictsItems.ndx = dictItem AND lang = %i', $langNdx, ' AND docState = %i', 1200,
				')');
			array_push($q, ')');
		}
		elseif ($mq === 'active' || $mq === '')
		{
			array_push($q, ' AND [dictsItems].[docStateMain] < %i', 4);
		}
		elseif ($mq === 'trash')
		{
			array_push($q, ' AND [dictsItems].[docStateMain] = %i', 4);
		}

		array_push($q, ' ORDER BY [dictsItems].[identifier], [dictsItems].[ndx]');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->dictsParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->dictsParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormDictItem
 * @package swdev\translation
 */
class FormDictItem extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('identifier');
					$this->addColumnInput ('text');
					$this->addColumnInput ('description');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('dict');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDictItem
 * @package swdev\translation
 */
class ViewDetailDictItem extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.translation.dc.DictItem');
	}
}

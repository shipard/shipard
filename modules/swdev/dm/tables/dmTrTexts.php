<?php

namespace swdev\dm;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Viewer\TableViewPanel, \E10\DbTable, \E10\utils;
use \e10\base\libs\UtilsBase;


/**
 * Class TableDMTrTexts
 * @package swdev\dm
 */
class TableDMTrTexts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.dmTrTexts', 'swdev_dm_dmTrTexts', 'icon-text');
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		$tt = new \swdev\dm\libs\TranslationTable($this->app());
		$tt->saveTableTrData ($recData['table'], $recData['lang']);
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		//$h ['info'][] = ['class' => 'title', 'value' => $recData ['classId']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['text']];

		return $h;
	}
}


/**
 * Class ViewDMTrTexts
 * @package swdev\dm
 */
class ViewDMTrTexts extends TableView
{
	var $langAll;

	public function init ()
	{
		parent::init();

		$this->setMainQueries ();

		$this->setPanels (TableView::sptQuery);

		$this->langAll = $this->app()->cfgItem ('swdev.tr.lang.langs', []);
	}

	public function renderRow ($item)
	{
		$textLang = $this->langAll[$item['lang']];

		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $textLang['flag'].' '.$item['text'];

		if ($item['srcText'])
		{

			$listItem['t2'] = ['text' => $item['srcTextsLangFlag'].' '.$item['srcTextText'], 'icon' => 'icon-level-up fa-flip-horizontal', 'class' => 'pl1'];
		}
		else
		{
//			$listItem ['t2'] = '...';
			$listItem['t2'] = ['text' => ' ... ', 'icon' => 'icon-level-up fa-rotate-90', 'class' => 'pl1'];
		}
		$props = [];
		if ($item['textType'] === 0) // table name
			$props[] = ['text' => $item['tableName'], 'icon' => 'icon-table', 'class' => ''];
		elseif ($item['textType'] === 1) // col name
		{
			$props[] = ['text' => $item['columnName'], 'icon' => 'icon-columns', 'class' => ''];
			$props[] = ['text' => $item['tableName'], 'icon' => 'icon-table', 'class' => ''];
		}
		elseif ($item['textType'] === 2) // col label
		{
			$props[] = ['text' => $item['columnName'], 'icon' => 'icon-columns', 'class' => ''];
			$props[] = ['text' => $item['tableName'], 'icon' => 'icon-table', 'class' => ''];
		}

		$listItem['t3'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [txt].*, ';

		array_push ($q, ' [tbls].[name] AS [tableName], [cols].[name] AS [columnName],');
		array_push ($q, ' [srcTexts].[text] AS srcTextText,');
		array_push ($q, ' [srcTextsLangs].[flag] AS srcTextsLangFlag, [srcTextsLangs].[code] AS srcTextsLangCode');
		array_push ($q, ' FROM [swdev_dm_dmTrTexts] AS [txt]');
		array_push ($q, ' LEFT JOIN [swdev_dm_tables] AS [tbls] ON [txt].[table] = [tbls].[ndx]');
		array_push ($q, ' LEFT JOIN [swdev_dm_columns] AS [cols] ON [txt].[column] = [cols].[ndx]');
		array_push ($q, ' LEFT JOIN [swdev_dm_dmTrTexts] AS [srcTexts] ON [txt].[srcText] = [srcTexts].[ndx]');
		array_push ($q, ' LEFT JOIN [swdev_translation_languages] AS [srcTextsLangs] ON [srcTexts].[lang] = [srcTextsLangs].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'([txt].[text] LIKE %s', '%'.$fts.'%',
				' OR [tbls].[id] LIKE %s', '%'.$fts.'%',
				' OR [cols].[id] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, '[txt].', ['[txt].[text]', '[txt].[ndx]']);
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
 * Class FormDMTrText
 * @package swdev\dm
 */
class FormDMTrText extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$srcTextRecData = NULL;
		$srcText = '';
		if ($this->recData['srcText'])
		{
			$srcTextRecData = $this->app()->db()->query('SELECT * FROM [swdev_dm_dmTrTexts] WHERE [ndx] = %i', $this->recData['srcText'])->fetch();
			if ($srcTextRecData)
			{
				$flagSrc = $this->app()->cfgItem ('swdev.tr.lang.langs.'.$srcTextRecData['lang'].'.flag', '');
				$flagDst = $this->app()->cfgItem ('swdev.tr.lang.langs.'.$this->recData['lang'].'.flag', '');
				$srcText = $flagSrc.' '.$srcTextRecData['text'].' ➜ '.$flagDst;
			}
		}

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				if ($this->recData['srcText'])
				{
					$this->openTab(self::ltVertical);
						if ($srcText !== '')
							$this->addStatic($srcText, self::coH2);
						$this->addColumnInput('text', 0, ['noLabel' => 1]);
					$this->closeTab();
				}
				else
				{
					$this->openTab();
						$this->addColumnInput('text');
					$this->closeTab();
				}
				$this->openTab ();
					$this->addColumnInput ('textType');
					$this->addColumnInput ('lang');
					$this->addColumnInput ('type');
					$this->addColumnInput ('table');
					$this->addColumnInput ('column');
					$this->addColumnInput ('srcText');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDMTrText
 * @package swdev\dm
 */
class ViewDetailDMTrText extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

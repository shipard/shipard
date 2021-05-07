<?php

namespace swdev\translation;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableDicts
 * @package swdev\translation
 */
class TableDicts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.translation.dicts', 'swdev_translation_dicts', 'Slovníky');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['identifier']];

		return $h;
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [swdev_translation_dicts] WHERE docState != 9800 ORDER BY [identifier], [ndx]");
		$dicts = [];
		foreach ($rows as $r)
		{
			$dict = [
				'ndx' => $r['ndx'], 'name' => $r['name'], 'id' => $r['identifier']
			];

			$dicts[$r['ndx']] = $dict;
		}

		$cfg ['swdev']['tr']['dicts'] = $dicts;
		file_put_contents(__APP_DIR__ . '/config/_swdev.tr.dicts.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewDicts
 * @package swdev\translation
 */
class ViewDicts extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
		$this->setPanels (TableView::sptQuery);

		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];


		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['name'];

/*
		$listItem ['i1'] = [
			['text' => '#'.$item['dstLangId'].'.'.$item['language'], 'class' => 'id'],
			['text' => $item['code'], 'class' => 'label label-default id'],
			['text' => strval($item['ndx']), 'class' => 'label label-default id'],
		];
*/

		$listItem ['t2'] = $item['identifier'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT dicts.*, ';
		array_push ($q, ' worldLanguages.name AS dstLangName, worldLanguages.id AS dstLangId ');
		array_push ($q, ' FROM [swdev_translation_dicts] AS [dicts]');
		array_push ($q, ' LEFT JOIN swdev_world_languages AS worldLanguages ON dicts.srcLanguage = worldLanguages.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [dicts].[name] LIKE %s', '%'.$fts.'%');
			array_push($q, ')');
		}

		$this->queryMain ($q, '[dicts].', ['[dicts].[name]', '[dicts].[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormDict
 * @package swdev\translation
 */
class FormDict extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Slovník', 'icon' => 'icon-book'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
				$this->addColumnInput ('name');
				$this->addColumnInput ('identifier');
				$this->addColumnInput ('srcLanguage');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDict
 * @package swdev\translation
 */
class ViewDetailDict extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

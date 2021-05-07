<?php

namespace swdev\translation;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableLanguages
 * @package swdev\translation
 */
class TableLanguages extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.translation.languages', 'swdev_translation_languages', 'Podporované jazyky');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData['name'] = '---';
		$language = $this->app()->loadItem($recData['language'], 'swdev.world.languages');
		if ($language)
			$recData['name'] = $language['name'];

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];
		//$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}

	public function saveConfig ()
	{
		$list = [];
		$rows = $this->app()->db->query ('SELECT * FROM [swdev_translation_languages] WHERE docState = 4000 ORDER BY [name], [ndx]');

		foreach ($rows as $r)
		{
			if ($r['useUI'])
				$list['ui'][] = $r['ndx'];
			if ($r['useWorld'])
				$list['world'][] = $r['ndx'];

			$list['langs'][$r['ndx']] = ['flag' => $r['flag'], 'name' => $r['name'], 'code' => $r['code']];
		}

		// -- save to file
		$cfg ['swdev']['tr']['lang'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_swdev.tr.lang.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewLanguages
 * @package swdev\translation
 */
class ViewLanguages extends TableView
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

		if ($item['flag'] !== '')
			$listItem ['emoji'] = $item['flag'];
		else
			$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['dstLangName'];
		$listItem ['i1'] = [
			['text' => '#'.$item['dstLangId'].'.'.$item['language'], 'class' => 'id'],
			['text' => $item['code'], 'class' => 'label label-default id'],
			['text' => strval($item['ndx']), 'class' => 'label label-default id'],
		];

		$listItem ['t2'] = [];
		if ($item['useWorld'])
			$listItem ['t2'][] = ['text' => 'Data Svět', 'icon' => 'icon-globe', 'class' => 'label label-default'];
		if ($item['useUI'])
			$listItem ['t2'][] = ['text' => 'Uživatelské rozhraní', 'icon' => 'icon-mouse-pointer', 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT trLanguages.*, ';
		array_push ($q, ' worldLanguages.name AS dstLangName, worldLanguages.id AS dstLangId ');
		array_push ($q, ' FROM [swdev_translation_languages] AS trLanguages');
		array_push ($q, ' LEFT JOIN swdev_world_languages AS worldLanguages ON trLanguages.language = worldLanguages.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'worldLanguages.[name] LIKE %s', '%'.$fts.'%',
				' OR worldLanguages.[id] LIKE %s', '%'.$fts.'%',
				' OR worldLanguages.[alpha2] LIKE %s', '%'.$fts,
				' OR worldLanguages.[alpha3b] LIKE %s', '%'.$fts,
				' OR worldLanguages.[alpha3t] LIKE %s', '%'.$fts
			);
			array_push($q, ' OR EXISTS (SELECT languageSrc FROM swdev_world_languagesTr ',
				'WHERE trLanguages.language = swdev_world_languagesTr.languageSrc AND swdev_world_languagesTr.name LIKE %s', '%'.$fts.'%', ')');
			array_push($q, ')');
		}

		$this->queryMain ($q, 'trLanguages.', ['worldLanguages.[name]', 'trLanguages.[ndx]']);
		$this->runQuery ($q);
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
 * Class FormLanguage
 * @package swdev\translation
 */
class FormLanguage extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Jazyk', 'icon' => 'x-content'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('language');
					$this->addColumnInput ('code');
					$this->addColumnInput ('flag');
					$this->addColumnInput ('useWorld');
					$this->addColumnInput ('useUI');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailLanguage
 * @package swdev\translation
 */
class ViewDetailLanguage extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

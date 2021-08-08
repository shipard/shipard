<?php

namespace swdev\translation;


use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Viewer\TableViewPanel, \E10\DbTable, \E10\utils;
use \e10\base\libs\UtilsBase;


/**
 * Class TableTranslators
 * @package swdev\translation
 */
class TableTranslators extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.translation.translators', 'swdev_translation_translators', 'Překladatelé');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData['worldLanguage'] = 0;
		$trLanguage = $this->app()->loadItem($recData['trLanguage'], 'swdev.translation.languages');
		if ($trLanguage)
			$recData['worldLanguage'] = $trLanguage['language'];

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		//$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];
		//$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}

	public function saveConfig ()
	{
		$list = [];
		$rows = $this->app()->db->query ('SELECT * FROM [swdev_translation_translators] WHERE docState = 4000 ORDER BY [person], [ndx]');

		foreach ($rows as $r)
		{
			if ($r['trLanguage'])
				$list[$r['person']][] = $r['trLanguage'];
		}

		// -- save to file
		$cfg ['swdev']['tr']['translators'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_swdev.tr.translators.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewTranslators
 * @package swdev\translation
 */
class ViewTranslators extends TableView
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

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['personName'];
		$listItem ['i1'] = ['text' => '#'.$item['personId'], 'class' => 'id'];

		$listItem ['t2'] = [];
		if ($item['dstLangName'])
			$listItem ['t2'][] = ['text' => $item['dstLangName'], 'icon' => 'icon-language', 'class' => ''];

		$listItem ['i2'] = ['text' => '#'.$item['dstLangId'].'.'.$item['worldLanguage'], 'class' => ''];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT translators.*, ';
		array_push ($q, ' persons.fullName AS personName, persons.id AS personId,');
		array_push ($q, ' worldLanguages.name AS dstLangName, worldLanguages.id AS dstLangId ');
		array_push ($q, ' FROM [swdev_translation_translators] AS translators');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON translators.person = persons.ndx');
		array_push ($q, ' LEFT JOIN swdev_world_languages AS worldLanguages ON translators.worldLanguage = worldLanguages.ndx');
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
				' OR worldLanguages.[alpha3t] LIKE %s', '%'.$fts,
				' OR persons.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push($q, ' OR EXISTS (SELECT languageSrc FROM swdev_world_languagesTr ',
				'WHERE translators.worldLanguage = swdev_world_languagesTr.languageSrc AND swdev_world_languagesTr.name LIKE %s', '%'.$fts.'%', ')');
			array_push($q, ')');
		}

		$this->queryMain ($q, 'translators.', ['persons.[lastName]', 'translators.[ndx]']);

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
 * Class FormTranslator
 * @package swdev\translation
 */
class FormTranslator extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Překladatel', 'icon' => 'icon-user-o'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('person');
					$this->addColumnInput ('trLanguage');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailTranslator
 * @package swdev\translation
 */
class ViewDetailTranslator extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

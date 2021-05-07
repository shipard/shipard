<?php

namespace swdev\world;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableLanguages
 * @package swdev\world
 */
class TableLanguages extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.languages', 'swdev_world_languages', 'Jazyky');
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
 * Class ViewLanguages
 * @package swdev\world
 */
class ViewLanguages extends TableView
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
		$listItem ['i1'] = ['text' => '#'.$item['id'].'.'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = [];

		if ($item['alpha2'] && $item['alpha2'] !== '')
			$listItem ['t2'][] = ['text' => $item['alpha2'], 'prefix' => 'a2', 'class' => 'label label-default'];

		if ($item['alpha3b'] && $item['alpha3b'] !== '')
			$listItem ['t2'][] = ['text' => $item['alpha3b'], 'prefix' => 'a3b', 'class' => 'label label-default'];
		if ($item['alpha3t'] && $item['alpha3t'] !== '')
			$listItem ['t2'][] = ['text' => $item['alpha3t'], 'prefix' => 'a3t', 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT languages.*';

		array_push ($q, ' FROM [swdev_world_languages] AS languages');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'languages.[name] LIKE %s', '%'.$fts.'%',
				' OR languages.[id] LIKE %s', '%'.$fts.'%',
				' OR languages.[alpha2] LIKE %s', '%'.$fts,
				' OR languages.[alpha3b] LIKE %s', '%'.$fts,
				' OR languages.[alpha3t] LIKE %s', '%'.$fts
			);
			array_push($q, ' OR EXISTS (SELECT languageDst FROM swdev_world_languagesTr ',
				'WHERE languages.ndx = languageSrc AND name LIKE %s', '%'.$fts.'%',
				')');

			array_push($q, ')');
		}

		$this->queryMain ($q, 'languages.', ['languages.[name]', 'languages.[ndx]']);

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
 * @package swdev\world
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
					$this->addColumnInput ('id');
					$this->addColumnInput ('name');
					$this->addColumnInput ('alpha2');
					$this->addColumnInput ('alpha3b');
					$this->addColumnInput ('alpha3t');
					$this->addColumnInput ('urlWikipedia');
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
		$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailLanguage
 * @package swdev\world
 */
class ViewDetailLanguage extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class ViewDetailLanguageTr
 * @package swdev\world
 */
class ViewDetailLanguageTr extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer('swdev.world.languagesTr', 'swdev.world.ViewLanguagesTr', ['languageSrc' => $this->item['ndx']]);
	}
}

<?php

namespace swdev\world;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableTerritoriesTr
 * @package swdev\world
 */
class TableTerritoriesTr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.territoriesTr', 'swdev_world_territoriesTr', 'Oblasti - Lokalizace');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $h;
	}
}


/**
 * Class ViewTerritoriesTr
 * @package swdev\world
 */
class ViewTerritoriesTr extends TableView
{
	var $territory = 0;

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();

		if ($this->queryParam ('territory'))
		{
			$this->territory = intval($this->queryParam('territory'));
			$this->addAddParam('territory', strval($this->territory));
		}
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['languageName'];
		$listItem ['i1'] = [
			['text' => '#'.$item['languageAlpha2'], 'class' => 'id'],
			['text' => '#'.$item['languageId'], 'class' => 'id'],
		];
		$listItem ['t2'] = [
			['text' => $item['name'], 'class' => 'label label-default'],
		];

		if ($item['urlWikipedia'])
			$listItem ['t2'][] = ['text' => $item['urlWikipedia'], 'icon' => 'icon-wikipedia-w', 'class' => 'label label-info pull-right'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [tr].*, ';
		array_push ($q, ' languages.id AS languageId, languages.name AS languageName, languages.alpha2 AS languageAlpha2');
		array_push ($q, ' FROM [swdev_world_territoriesTr] AS [tr]');
		array_push ($q, ' LEFT JOIN swdev_world_territories AS territories ON tr.territory = territories.ndx');
		array_push ($q, ' LEFT JOIN swdev_world_languages AS languages ON tr.language = languages.ndx');
		array_push ($q, ' WHERE 1');

		// -- territory
		if ($this->territory)
			array_push ($q, ' AND [tr].territory = %i', $this->territory);

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'(tr.[name] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, 'tr.', ['languages.[alpha2]', 'languages.[ndx]', 'tr.[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormTerritoryTr
 * @package swdev\world
 */
class FormTerritoryTr extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Překlad', 'icon' => 'icon-language'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('urlWikipedia');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('territory');
					$this->addColumnInput ('language');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

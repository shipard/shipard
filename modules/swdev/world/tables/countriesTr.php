<?php

namespace swdev\world;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableCountriesTr
 * @package swdev\world
 */
class TableCountriesTr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.countriesTr', 'swdev_world_countriesTr', 'Země - Lokalizace');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['nameCommon']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['nameOfficial']];

		return $h;
	}
}


/**
 * Class ViewCountriesTr
 * @package swdev\world
 */
class ViewCountriesTr extends TableView
{
	var $country = 0;

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();

		if ($this->queryParam ('country'))
		{
			$this->country = intval($this->queryParam('country'));
			$this->addAddParam('country', strval($this->country));
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
			['text' => $item['nameOfficial'], 'class' => 'label label-default'],
			['text' => $item['nameCommon'], 'class' => 'label label-default'],
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
		array_push ($q, ' FROM [swdev_world_countriesTr] AS [tr]');
		array_push ($q, ' LEFT JOIN swdev_world_countries AS countries ON tr.country = countries.ndx');
		array_push ($q, ' LEFT JOIN swdev_world_languages AS languages ON tr.language = languages.ndx');
		array_push ($q, ' WHERE 1');

		// -- country
		if ($this->country)
			array_push ($q, ' AND [tr].country = %i', $this->country);

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'(tr.[nameCommon] LIKE %s', '%'.$fts.'%',
				' OR tr.[nameOfficial] LIKE %s', '%'.$fts.'%',
				')'
			);
			array_push($q, ')');
		}

		$this->queryMain ($q, 'tr.', ['languages.[alpha2]', 'languages.[ndx]', 'tr.[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormCountryTr
 * @package swdev\world
 */
class FormCountryTr extends TableForm
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
					$this->addColumnInput ('nameCommon');
					$this->addColumnInput ('nameOfficial');
					$this->addColumnInput ('urlWikipedia');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('country');
					$this->addColumnInput ('language');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

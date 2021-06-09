<?php

namespace swdev\world;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableCountries
 * @package swdev\world
 */
class TableCountries extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.countries', 'swdev_world_countries', 'Země');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['nameCommon']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		if ($recData['flag'] !== '')
		{
			$h['emoji'] = $recData['flag'];
			unset ($h['icon']);
		}

		return $h;
	}
}


/**
 * Class ViewCountries
 * @package swdev\world
 */
class ViewCountries extends TableView
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

		//$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['emoji'] = $item['flag'];
		$listItem ['t1'] = $item['nameCommon'];
		$listItem ['i1'] = ['text' => '#'.$item['id'].'.'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = $item['nameOfficial'];;


		$listItem ['i2'] = [];

		//$listItem ['t2'][] = ['text' => $item['cca2'], 'class' => 'label label-default'];

		if ($item['cca2'] && $item['cca2'] !== '')
			$listItem ['i2'][] = ['text' => $item['cca2'], 'class' => 'label label-default'];
		if ($item['cca3'] && $item['cca3'] !== '')
			$listItem ['i2'][] = ['text' => $item['cca3'], 'class' => 'label label-default'];
		if ($item['ccn3'] && $item['ccn3'] !== 0)
			$listItem ['i2'][] = ['text' => strval($item['ccn3']), 'class' => 'label label-default'];
		if ($item['callingCodes'] && $item['callingCodes'] !== '')
			$listItem ['i2'][] = ['text' => $item['callingCodes'], 'icon' => 'system/iconPhone', 'class' => 'label label-default'];
		if ($item['tlds'] && $item['tlds'] !== '')
			$listItem ['i2'][] = ['text' => $item['tlds'], 'icon' => 'icon-globe', 'class' => 'label label-info'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT countries.*';

		array_push ($q, ' FROM [swdev_world_countries] AS countries');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' countries.[nameCommon] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR countries.[id] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR EXISTS (SELECT country FROM swdev_world_countriesTr ',
				'WHERE countries.ndx = country AND (nameCommon LIKE %s', '%'.$fts.'%', ' OR nameOfficial LIKE %s)', '%'.$fts.'%',
				')');
			array_push($q, ')');
		}

		$this->queryMain ($q, 'countries.', ['countries.[id]', 'countries.[ndx]']);

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
 * Class FormCountry
 * @package swdev\world
 */
class FormCountry extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Země', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Jazyky', 'icon' => 'icon-language'];
			$tabs ['tabs'][] = ['text' => 'Měny', 'icon' => 'icon-money'];
			$tabs ['tabs'][] = ['text' => 'Oblasti', 'icon' => 'icon-map'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('id');
					$this->addColumnInput ('nameCommon');
					$this->addColumnInput ('nameOfficial');
					$this->addColumnInput ('cca2');
					$this->addColumnInput ('cca3');
					$this->addColumnInput ('ccn3');
					$this->addColumnInput ('tlds');
					$this->addColumnInput ('callingCodes');
					$this->addColumnInput ('flag');
					$this->addColumnInput ('independent');
					$this->addColumnInput ('urlWikipedia');
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addList ('languages');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addList ('currencies');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addList ('territories');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailCountry
 * @package swdev\world
 */
class ViewDetailCountry extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class ViewDetailCountryTr
 * @package swdev\world
 */
class ViewDetailCountryTr extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer('swdev.world.countriesTr', 'swdev.world.ViewCountriesTr', ['country' => $this->item['ndx']]);
	}
}

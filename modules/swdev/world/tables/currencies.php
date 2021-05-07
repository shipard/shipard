<?php

namespace swdev\world;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableCurrencies
 * @package swdev\world
 */
class TableCurrencies extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.world.currencies', 'swdev_world_currencies', 'Měny');
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
 * Class ViewCurrencies
 * @package swdev\world
 */
class ViewCurrencies extends TableView
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

		$listItem ['t2'][] = ['text' => $item['id'], 'class' => 'label label-default'];

		if ($item['symbol'] && $item['symbol'] !== '')
			$listItem ['t2'][] = ['text' => $item['symbol'], 'class' => 'label label-default'];
		if ($item['symbolNative'] && $item['symbolNative'] !== '')
			$listItem ['t2'][] = ['text' => $item['symbolNative'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT currencies.*';

		array_push ($q, ' FROM [swdev_world_currencies] AS currencies');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' currencies.[name] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR currencies.[id] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR EXISTS (SELECT currency FROM swdev_world_currenciesTr ',
				'WHERE currencies.ndx = currency AND (name LIKE %s', '%'.$fts.'%', ' OR namePlural LIKE %s)', '%'.$fts.'%',
				')');
			array_push($q, ')');
		}

		$this->queryMain ($q, 'currencies.', ['currencies.[name]', 'currencies.[ndx]']);

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
class FormCurrency extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Měna', 'icon' => 'x-content'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('id');
					$this->addColumnInput ('name');
					$this->addColumnInput ('namePlural');
					$this->addColumnInput ('symbol');
					$this->addColumnInput ('symbolNative');
					$this->addColumnInput ('decimals');
					$this->addColumnInput ('urlWikipedia');
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailCurrency
 * @package swdev\world
 */
class ViewDetailCurrency extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class ViewDetailCurrencyTr
 * @package swdev\world
 */
class ViewDetailCurrencyTr extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer('swdev.world.currenciesTr', 'swdev.world.ViewCurrenciesTr', ['currency' => $this->item['ndx']]);
	}
}

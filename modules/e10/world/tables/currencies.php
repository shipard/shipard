<?php

namespace e10\world;

use \e10\TableView, \e10\TableViewDetail, \e10\DbTable, \e10\world;


/**
 * Class TableCurrencies
 * @package e10\world
 */
class TableCurrencies extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.currencies', 'e10_world_currencies', 'Měny');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['id']];

		return $h;
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$c = world::currency($this->app(), $pk);
		if ($c)
		{
			$refTitle = ['text' => strtoupper($c['i']).' '.$c['t']];
			return $refTitle;
		}

		return '';
	}
}


/**
 * Class ViewCurrencies
 * @package e10\world
 */
class ViewCurrencies extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['trName'];
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

		$q [] = 'SELECT currencies.*, tr.name AS trName';
		array_push ($q, ' FROM [e10_world_currencies] AS currencies');
		array_push ($q, ' LEFT JOIN e10_world_currenciesTr AS tr ON currencies.ndx = tr.currency AND tr.language = 102');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' currencies.[name] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR currencies.[id] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR EXISTS (SELECT currency FROM e10_world_currenciesTr ',
				'WHERE currencies.ndx = currency AND (name LIKE %s', '%'.$fts.'%', ' OR namePlural LIKE %s)', '%'.$fts.'%',
				')');
			array_push($q, ')');
		}

		array_push ($q, ' ORDER BY [currencies].name, [currencies].ndx');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class ViewDetailCurrency
 * @package e10\world
 */
class ViewDetailCurrency extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

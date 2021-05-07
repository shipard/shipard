<?php

namespace e10\world;

use \e10\TableView, \e10\TableViewDetail, \e10\DbTable;


/**
 * Class TableLanguages
 * @package e10\world
 */
class TableLanguages extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.languages', 'e10_world_languages', 'Jazyky');
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
 * @package e10\world
 */
class ViewLanguages extends TableView
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

		if ($item['trName'])
			$listItem ['t1'] = $item['trName'];
		else
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

		$q [] = 'SELECT languages.*, tr.name AS trName';
		array_push ($q, ' FROM [e10_world_languages] AS languages');
		array_push ($q, ' LEFT JOIN e10_world_languagesTr AS tr ON languages.ndx = tr.languageSrc AND tr.languageDst = 102');
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
			array_push($q, ' OR EXISTS (SELECT languageDst FROM e10_world_languagesTr ',
				'WHERE languages.ndx = languageSrc AND name LIKE %s', '%'.$fts.'%',
				')');

			array_push($q, ')');
		}

		array_push ($q, ' ORDER BY [languages].name, [languages].ndx');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class ViewDetailLanguage
 * @package e10\world
 */
class ViewDetailLanguage extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

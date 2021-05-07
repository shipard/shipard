<?php

namespace e10\world;


use \e10\TableView, \e10\TableViewDetail, \e10\TableViewPanel, \e10\DbTable, \e10\world;


/**
 * Class TableCountries
 * @package e10\world
 */
class TableCountries extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.countries', 'e10_world_countries', 'Země');
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

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;
		if (!$pk)
			return '';

		$country = world::country($this->app(), $pk);
		if ($country)
		{
			$refTitle = ['text' => strtoupper($country['i']).' '.$country['f'].' '.$country['t']];
			return $refTitle;
		}

		return '';
	}
}


/**
 * Class ViewCountries
 * @package e10\world
 */
class ViewCountries extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['emoji'] = $item['flag'];

		if ($item['trNameCommon'])
			$listItem ['t1'] = $item['trNameCommon'];
		else
			$listItem ['t1'] = $item['nameCommon'];

		$listItem ['i1'] = ['text' => '#'.$item['id'].'.'.$item['ndx'], 'class' => 'id'];

		if ($item['trNameOfficial'])
			$listItem ['t2'] = $item['trNameOfficial'];
		else
			$listItem ['t2'] = $item['nameOfficial'];

		$listItem ['i2'] = [];

		//$listItem ['t2'][] = ['text' => $item['cca2'], 'class' => 'label label-default'];

		if ($item['cca2'] && $item['cca2'] !== '')
			$listItem ['i2'][] = ['text' => $item['cca2'], 'class' => 'label label-default'];
		if ($item['cca3'] && $item['cca3'] !== '')
			$listItem ['i2'][] = ['text' => $item['cca3'], 'class' => 'label label-default'];
		if ($item['ccn3'] && $item['ccn3'] !== 0)
			$listItem ['i2'][] = ['text' => strval($item['ccn3']), 'class' => 'label label-default'];
		if ($item['callingCodes'] && $item['callingCodes'] !== '')
			$listItem ['i2'][] = ['text' => $item['callingCodes'], 'icon' => 'icon-phone', 'class' => 'label label-default'];
		if ($item['tlds'] && $item['tlds'] !== '')
			$listItem ['i2'][] = ['text' => $item['tlds'], 'icon' => 'icon-globe', 'class' => 'label label-info'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT countries.*, tr.nameCommon AS trNameCommon, tr.nameOfficial AS trNameOfficial ';
		array_push ($q, ' FROM [e10_world_countries] AS countries');
		array_push ($q, ' LEFT JOIN e10_world_countriesTr AS tr ON countries.ndx = tr.country AND tr.language = 102');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' countries.[nameCommon] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR countries.[id] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR EXISTS (SELECT country FROM e10_world_countriesTr ',
				'WHERE countries.ndx = country AND (nameCommon LIKE %s', '%'.$fts.'%', ' OR nameOfficial LIKE %s)', '%'.$fts.'%',
				')');
			array_push($q, ')');
		}

		array_push ($q, ' ORDER BY [countries].id, [countries].ndx');
		array_push ($q, $this->sqlLimit ());

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

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class ViewDetailCountry
 * @package e10\world
 */
class ViewDetailCountry extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

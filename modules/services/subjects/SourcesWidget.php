<?php

namespace services\subjects;

use \e10\Application, \e10\utils;


/**
 * Class SourcesWidget
 * @package services\subjects
 */
class SourcesWidget extends \E10\widgetBoard
{
	var $totalCount = 0;
	var $queryDefinition = [];

	public function init ()
	{
		$this->panelWidth = 'e10-2x';
		$this->addParams();
		parent::init();
	}

	public function setDefinition ($d)
	{
		$this->definition = ['class' => 'services.subjects.SourcesWidget', 'icon' => 'icon-eye',	'type' => 'board'];
	}

	function addParams ()
	{
		// -- branches
		$branches = $this->app->cfgItem ('services.subjects.branches.branches');
		$this->addCheckboxes($branches, 'branches', 'Obory', 'fn');

		// -- activities
		$activities = $this->app->cfgItem ('services.subjects.activities');
		$this->addCheckboxes($activities, 'activities', 'Činnosti');

		// -- kinds
		$kinds = $this->app->cfgItem ('services.subjects.kinds');
		$this->addCheckboxes($kinds, 'kinds', 'Druhy');

		// -- sizes
		$sizes = $this->app->cfgItem ('services.subjects.sizes');
		$this->addCheckboxes($sizes, 'sizes', 'Velikosti');

		// -- commodities
		//$commodities = $this->app->cfgItem ('services.subjects.commodities');
		//$this->addCheckboxes($commodities, 'commodities', 'Komodity', 'fn');

		$region1 = $this->app->cfgItem('nomenc.cz-nuts-3');
		$this->addCheckboxes($region1, 'region1', ['text' => 'Kraje', 'icon' => 'icon-tags']);

		$region2 = $this->app->cfgItem('nomenc.cz-nuts-4');
		$this->addCheckboxes($region2, 'region2', ['text' => 'Okresy', 'icon' => 'icon-tags']);

		// -- text
		// $this->addParam ('string', 'query.text.text', ['title' => 'hledaný text ⏎', 'groupTitle' => ['text' => 'Text', 'icon' => 'icon-search'], 'place' => 'panel']);
	}

	function addCheckboxes ($enum, $qryId, $title)
	{
		if (count($enum) !== 0)
		{
			$chbxs = [];
			forEach ($enum as $enumNdx => $r)
			{
				if (isset ($r['state']) && $r['state'] === 5)
					continue;
				$chbxs[$enumNdx] = ['title' => $r['sn'], 'id' => $enumNdx];
			}

			$this->addParam ('checkboxes', 'query.'.$qryId, ['items' => $chbxs, 'title' => $title, 'place' => 'panel']);
		}
	}

	function loadDataOverview()
	{
		$this->loadDataOverview_Count();
	}

	function loadDataOverview_Count()
	{
		$q[] = 'SELECT SUM([cnt]) AS [cnt] FROM [services_subjects_subjectsCounters] WHERE 1';
		$this->paramsQuery ($q);

		$res = $this->db()->query($q)->fetch();
		$this->totalCount = $res['cnt'];
	}

	function paramsQuery (&$q)
	{
		$qv = utils::queryValues();
		if (isset($qv['branches']))
		{
			array_push ($q, ' AND [branch] IN %in', array_keys($qv['branches']));
		}

		if (isset($qv['activities']))
		{
			array_push ($q, ' AND [activity] IN %in', array_keys($qv['activities']));
		}

		if (isset($qv['commodities']))
		{
			array_push ($q, ' AND [commodity] IN %in', array_keys($qv['commodities']));
		}

		if (isset($qv['sizes']))
		{
			array_push ($q, ' AND [size] IN %in', array_keys($qv['sizes']));
		}

		if (isset($qv['kinds']))
		{
			array_push ($q, ' AND [kind] IN %in', array_keys($qv['kinds']));
		}

		if (isset($qv['region1']))
		{
			array_push ($q, ' AND [region1] IN %in', array_keys($qv['region1']));
		}

		if (isset($qv['region2']))
		{
			array_push ($q, ' AND [region2] IN %in', array_keys($qv['region2']));
		}
	}

	function createQueryDefinition ()
	{
		$qv = utils::queryValues();
		$this->createQueryDefinitionAdd ($qv, 'branches');
		$this->createQueryDefinitionAdd ($qv, 'activities');
		$this->createQueryDefinitionAdd ($qv, 'commodities');
		$this->createQueryDefinitionAdd ($qv, 'sizes');
		$this->createQueryDefinitionAdd ($qv, 'kinds');
		$this->createQueryDefinitionAdd ($qv, 'region1');
		$this->createQueryDefinitionAdd ($qv, 'region2');
	}

	function createQueryDefinitionAdd ($qv, $id)
	{
		if (isset($qv[$id]))
			$this->queryDefinition[$id] = array_keys($qv[$id]);
	}

	public function createContent ()
	{
		parent::createContent();

		$this->panelStyle = self::psFixed;

		$this->createQueryDefinition();
		$this->loadDataOverview();

		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 12]);
				$this->createTopBar();
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);

		if (!$this->totalCount)
			return;

		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 6]);
				//$this->graphActivities();
				$this->graphBranches();
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 6]);
				//$this->graphCommodities();
				$this->graphActivities();
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);

		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 6]);
				$this->graphKinds();
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 6]);
				$this->graphRegion1();
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);
	}

	function createTopBar ()
	{
		$topBar = [];
		$topBar [] = ['text' => 'Celkem '.utils::nf($this->totalCount).' zdrojů', 'class' => 'e10-widget-big-text'];

		if ($this->app()->remote !== '')
		{
			$topBar[] = [
					'type' => 'action', 'action' => 'addwizard', 'text' => 'Stáhnout zdroje', 'icon' => 'icon-download',
					'class' => 'pull-right', 'actionClass' => 'btn-sm btn-success',
					'data-table' => 'services.subjects.subjects', 'data-class' => 'e10crm.core.AddSourcesWizard',
					'data-addparams' => 'queryDefinition=' . base64_encode(json_encode($this->queryDefinition))
			];
		}

		$this->addContent (['type' => 'line', 'line' => $topBar]);
	}

	function graphRegion1 ()
	{
		$region1 = $this->app->cfgItem('nomenc.cz-nuts-3');

		$q[] = 'SELECT region1, SUM([cnt]) AS [cnt] FROM [services_subjects_subjectsCounters] WHERE 1';
		$this->paramsQuery ($q);
		array_push ($q, ' GROUP BY region1');
		array_push ($q, ' ORDER BY cnt DESC');
		$rows = $this->db()->query($q);

		$pieData = [];
		foreach ($rows as $r)
		{
			$pieData[] = [$region1[$r['region1']]['sn'].' ('.utils::nf($r['cnt']).')', $r['cnt']];
		}

		$this->addContent(['type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData, 'title' => ['text' =>'Kraje', 'class' => 'h1']]);
	}

	function graphBranches ()
	{
		$enum = $this->app->cfgItem('services.subjects.branches.branches');

		$q[] = 'SELECT branch, SUM([cnt]) AS [cnt] FROM [services_subjects_subjectsCounters] WHERE 1';
		$this->paramsQuery ($q);
		array_push ($q, ' GROUP BY 1');
		array_push ($q, ' ORDER BY cnt DESC');
		$rows = $this->db()->query($q);

		$data = [];
		foreach ($rows as $r)
			$data[] = ['title' => $enum[$r['branch']]['sn'].' ('.utils::nf($r['cnt']).')', 'cnt' => $r['cnt']];

		$maxRows = 8;
		$cutedData = [];
		$cutedSum = [];

		utils::cutRows ($data, $cutedData, ['cnt'], $cutedSum, $maxRows);
		if (count($cutedSum))
		{
			$cutedSum['title'] = 'Ostatní'.' ('.utils::nf ($cutedSum['cnt']).')';
			$cutedData[] = $cutedSum;
		}

		$pieData = [];
		foreach ($cutedData as $r)
			$pieData[] = [$r['title'], $r['cnt']];

		$this->addContent(['type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData, 'title' => ['text' =>'Obory', 'class' => 'h1']]);
	}

	function graphActivities ()
	{
		$enum = $this->app->cfgItem('services.subjects.activities');

		$q[] = 'SELECT activity, SUM([cnt]) AS [cnt] FROM [services_subjects_subjectsCounters] WHERE 1';
		$this->paramsQuery ($q);
		array_push ($q, ' GROUP BY 1');
		array_push ($q, ' ORDER BY cnt DESC');
		$rows = $this->db()->query($q);

		$data = [];
		foreach ($rows as $r)
			$data[] = ['title' => $enum[$r['activity']]['sn'].' ('.utils::nf($r['cnt']).')', 'cnt' => $r['cnt']];

		$maxRows = 8;
		$cutedData = [];
		$cutedSum = [];

		utils::cutRows ($data, $cutedData, ['cnt'], $cutedSum, $maxRows);
		if (count($cutedSum))
		{
			$cutedSum['title'] = 'Ostatní'.' ('.utils::nf ($cutedSum['cnt']).')';
			$cutedData[] = $cutedSum;
		}

		$pieData = [];
		foreach ($cutedData as $r)
			$pieData[] = [$r['title'], $r['cnt']];

		$this->addContent(['type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData, 'title' => ['text' =>'Činnosti', 'class' => 'h1']]);
	}

	function graphCommodities ()
	{
		$enum = $this->app->cfgItem('services.subjects.commodities');

		$q[] = 'SELECT commodity, SUM([cnt]) AS [cnt] FROM [services_subjects_subjectsCounters] WHERE 1';
		$this->paramsQuery ($q);
		array_push ($q, ' GROUP BY 1');
		array_push ($q, ' ORDER BY cnt DESC');
		$rows = $this->db()->query($q);

		$data = [];
		foreach ($rows as $r)
			$data[] = ['title' => $enum[$r['commodity']]['sn'].' ('.utils::nf($r['cnt']).')', 'cnt' => $r['cnt']];

		$maxRows = 8;
		$cutedData = [];
		$cutedSum = [];

		utils::cutRows ($data, $cutedData, ['cnt'], $cutedSum, $maxRows);
		if (count($cutedSum))
		{
			$cutedSum['title'] = 'Ostatní'.' ('.utils::nf ($cutedSum['cnt']).')';
			$cutedData[] = $cutedSum;
		}

		$pieData = [];
		foreach ($cutedData as $r)
			$pieData[] = [$r['title'], $r['cnt']];

		$this->addContent(['type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData, 'title' => ['text' =>'Komodity', 'class' => 'h1']]);
	}

	function graphKinds ()
	{
		$enum = $this->app->cfgItem('services.subjects.kinds');

		$q[] = 'SELECT kind, SUM([cnt]) AS [cnt] FROM [services_subjects_subjectsCounters] WHERE 1';
		$this->paramsQuery ($q);
		array_push ($q, ' GROUP BY 1');
		array_push ($q, ' ORDER BY cnt DESC');
		$rows = $this->db()->query($q);

		$pieData = [];
		foreach ($rows as $r)
		{
			$pieData[] = [$enum[$r['kind']]['sn'].' ('.utils::nf($r['cnt']).')', $r['cnt']];
		}

		$this->addContent(['type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData, 'title' => ['text' =>'Druhy', 'class' => 'h1']]);
	}


	public function title() {return FALSE;}
}

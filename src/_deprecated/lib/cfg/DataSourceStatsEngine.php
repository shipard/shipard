<?php

namespace lib\cfg;

use \e10\Utility, e10\utils;


class DataSourceStatsEngine extends Utility
{
	var $dsStatsCfg;

	var $countsData = [];
	var $calcData = [];
	var $firstPeriodId;
	var $lastPeriodId;

	function loadCounts ($cfgCountsId, $type)
	{
		$cfg = $this->dsStatsCfg['counts'][$cfgCountsId];

		$cntId = $cfg['cntId'] . (($type === 'all') ? '-yearly': '-monthly');

		$q = [];
		array_push($q, 'SELECT * FROM [e10_base_statsCounters] WHERE 1');
		array_push($q, ' AND [id] = %s', $cntId);

		if ($type === '12m')
		{
			array_push($q, ' AND ([s1] <= %s', $this->firstPeriodId, ' AND [s1] >= %s)', $this->lastPeriodId);
		}

		array_push($q, ' ORDER BY s1, s2');

		$data = [];
		$header = [];
		$tableData = [];

		$tableHeader = ['docType' => 'Druh dokladu'];

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$period = $r['s1'];
			$docType = $r[$cfg['subTypeCol']];
			if (!isset($data[$period]))
				$data[$period] = ['date' => $period, 'total' => 0];
			if (!isset($data[$period][$docType]))
				$data[$period][$docType] = 0;
			$data[$period][$docType] += $r['cnt'];
			$data[$period]['total'] += $r['cnt'];

			if (!isset($header[$docType]))
				$header[$docType] = $this->app->cfgItem ($cfg['subTypeCfgPath'].'.'.$docType.'.'.$cfg['subTypeCfgTitle'], '---');

			if (!isset($sumData[$docType]))
				$sumData[$docType] = 0;
			$sumData[$docType] += $r['cnt'];

			if (!isset($tableData[$docType]))
				$tableData[$docType] = ['docType' => $header[$docType]];
			if (!isset($tableData[$docType][$period]))
				$tableData[$docType][$period] = 0;
			$tableData[$docType][$period] += $r['cnt'];

			if (!isset($tableHeader[$period]))
				$tableHeader[$period] = '+'.$period;
		}
		$header['date'] = 'datum';
		$header['total'] = 'celkem';

		$this->countsData[$type][$cfgCountsId]['data'] = $data;

		// -- table data
		$this->countsData[$type][$cfgCountsId]['tableData'] = $tableData;
		$this->countsData[$type][$cfgCountsId]['tableHeader'] = $tableHeader;

		// -- graph pie
		asort ($sumData);
		$sumData = array_reverse($sumData, TRUE);
		$this->countsData[$type][$cfgCountsId]['header'] = $header;
		$pieData = [];
		$totalCnt = 0;
		foreach ($sumData as $docType => $cnt)
		{
			$pieData[] = [$header[$docType] . ' (' . utils::nf($cnt) . ')', $cnt];
			$totalCnt += $cnt;
		}
		$graphPie = [
				'title' => [
						['text' => $cfg['title'], 'icon' => $cfg['icon'], 'class' => 'h1'],
						['text' => utils::nf($totalCnt), 'suffix' => $this->lastPeriodId.' - '.$this->firstPeriodId, 'class' => 'pull-right']
				],
				'type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData];
		$this->countsData[$type][$cfgCountsId]['graphPie'] = $graphPie;

		// -- graph bars
		$graphBars = [
				'title' => [['text' => $cfg['title']],
				'class' => 'h1 block center', 'icon' => 'icon-file-o'],
				'type' => 'graph', 'graphType' => 'bar', 'XKey' => 'date', 'stacked' => 1, 'header' => $this->countsData[$type][$cfgCountsId]['header'],
				'disabledCols' => ['total'], 'graphData' => $this->countsData[$type][$cfgCountsId]['data']
		];
		$this->countsData[$type][$cfgCountsId]['graphBars'] = $graphBars;

		// -- graph slice
		$graphSpline = [
				'title' => ['text' => $cfg['title'], 'class' => 'h1 block center', 'icon' => 'icon-file-o'],
				'type' => 'graph', 'graphType' => 'line', 'XKey' => 'date', 'header' => $this->countsData[$type][$cfgCountsId]['header'],
				'disabledCols' => ['total'], 'graphData' => $this->countsData[$type][$cfgCountsId]['data']
		];
		$this->countsData[$type][$cfgCountsId]['graphSpline'] = $graphSpline;
	}

	function createCalculation ()
	{
		// -- collect data
		$data = [];
		$header = ['period' => 'Období'];

		foreach ($this->countsData['12m'] as $partId => $partData)
		{
			foreach ($partData['tableData'] as $subType => $periods)
			{
				foreach ($periods as $periodId => $cnt)
				{
					if ($periodId === 'docType')
						continue;

					$calcGroupId = '';
					if (isset($this->dsStatsCfg['counts'][$partId]['subTypesCalcGroups'][$subType]))
						$calcGroupId = $this->dsStatsCfg['counts'][$partId]['subTypesCalcGroups'][$subType];
					elseif (isset($this->dsStatsCfg['counts'][$partId]['subTypesCalcGroups']['-']))
						$calcGroupId = $this->dsStatsCfg['counts'][$partId]['subTypesCalcGroups']['-'];

					if ($calcGroupId === '')
						continue;

					if (!isset($data[$periodId]))
						$data[$periodId] = ['period' => $periodId];
					if (!isset($data[$periodId][$calcGroupId]))
						$data[$periodId][$calcGroupId] = 0;

					$data[$periodId][$calcGroupId] += $cnt;

					if (!isset($header[$calcGroupId]))
						$header[$calcGroupId] = ' '.$this->dsStatsCfg['calcGroups'][$calcGroupId]['title'];
				}
			}
		}

		// -- calc price
		$prices = ['cashreg' => 0.3, 'purchase' => 0.4, 'docs' => 3, 'messages' => 0.2, 'workOrders' => 3];
		foreach ($data as $periodId => $counts)
		{
			foreach ($counts as $calcGroupId => $cnt)
			{
				if ($calcGroupId === 'period')
					continue;

				if (!isset($data[$periodId][$calcGroupId]))
					$data[$periodId]['price'] = 0.0;

				$data[$periodId]['price'] += $prices[$calcGroupId] * $cnt;
			}
		}

		$header ['price'] = ' Cena';

		$this->calcData['table'] = $data;
		$this->calcData['header'] = $header;
	}

	public function loadData()
	{
		foreach ($this->dsStatsCfg['counts'] as $cfgCountsId => $cfgCountsDef)
		{
			$this->loadCounts($cfgCountsId, 'all');
			$this->loadCounts($cfgCountsId, '12m');
		}

		$this->createCalculation();
	}

	function init()
	{
		$this->dsStatsCfg = $this->app()->cfgItem ('dsStats');

		$todayDate = utils::today();
		$startDate = clone $todayDate;
		$startDate->sub (new \DateInterval('P12M'));

		$this->firstPeriodId = $todayDate->format('Y-m');
		$this->lastPeriodId = $startDate->format('Y-m');
	}

	public function run()
	{
		$this->init();
		$this->loadData();
	}
}


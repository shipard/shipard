<?php

namespace hosting\core\libs;

use \Shipard\Utils\Utils, \Shipard\Base\Content;


/**
 * Class HostingReviewDataSources
 */
class HostingReviewDataSources extends Content
{
	var $partner = 0;

	function create()
	{
		$biggestBySpace = $this->createBiggestDataSources('space');
		$biggestByDocs = $this->createBiggestDataSources('docs');
		$biggestByUsersAll = $this->createBiggestDataSources('usersAll');

		$tabs = [
			['title' => ['icon' => 'icon-hdd-o', 'text' => 'Velikost'], 'content' => $biggestBySpace],
			['title' => ['icon' => 'icon-file-text-o', 'text' => 'Doklady'], 'content' => $biggestByDocs],
			['title' => ['icon' => 'icon-user', 'text' => 'Uživatelé'], 'content' => $biggestByUsersAll]
		];

		if ($this->partner)
		{
      /*
			$report = new \e10pro\hosting\server\libs\DataSourcesPlansReport($this->app());
			$report->partnerNdx = $this->partner;
			$report->disableEdit = TRUE;
			$report->init();
			$report->createContent();

			$plansContent = $report->content;
			$plansContent[0]['pane'] = 'e10-pane e10-pane-table';
			$plansContent[1]['pane'] = 'e10-pane e10-pane-table';

			unset ($plansContent[0]['main']);

			$tabs[] = ['title' => ['icon' => 'icon-money', 'text' => 'Fakturace'], 'content' => $plansContent];
      */
		}

		$this->addContent(['tabsId' => 'mainTabs', 'selectedTab' => '0', 'tabs' => $tabs]);
	}

	function createBiggestDataSources ($orderBy)
	{
		$pieData = [];
		$data = [];
		$pks = [];
		$totals = ['usageTotal' => 0, 'cntDocuments12m' => 0];

		// -- top 'ten'
		$q[] = 'SELECT stats.usageDb as usageDb, stats.usageFiles as usageFiles, stats.usageTotal as usageTotal,';
		array_push($q, ' stats.cntDocuments12m as cntDocuments12m, stats.cntUsersAll1m as cntUsersAll1m,');
		array_push($q, ' stats.datasource, ds.name as dsName, ds.shortName as dsShortName');
		array_push($q, ' FROM hosting_core_dsStats AS stats');
		array_push($q, ' LEFT JOIN hosting_core_dataSources AS ds ON stats.dataSource = ds.ndx');
		array_push($q, ' WHERE ds.docState = 4000');
		array_push($q, ' AND ds.dsType = %i', 0, ' AND ds.condition = %i', 2);
		if ($this->partner)
			array_push ($q, ' AND ds.[partner] = %i', $this->partner);
		array_push($q, ' ORDER BY');

		switch ($orderBy)
		{
			case 'space': array_push($q, ' stats.usageTotal DESC'); break;
			case 'docs': array_push($q, ' stats.cntDocuments12m DESC'); break;
			case 'usersAll': array_push($q, ' stats.cntUsersAll1m DESC'); break;
		}

		array_push($q, ' LIMIT 24');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
					'name' => $r['dsName'],
					'usageDb' => Utils::memf($r['usageDb']), 'usageFiles' => Utils::memf($r['usageFiles']),
					'usageTotal' => Utils::memf($r['usageTotal']),
					'cntDocuments12m' => $r['cntDocuments12m'],
					'cntUsersAll1m' => $r['cntUsersAll1m']
			];
			$data[] = $item;
      $sn = $r['dsShortName'] === '' ? $r['dsName'] : $r['dsShortName'];
			switch ($orderBy)
			{
				case 'space': $pieData[] = [$sn, $r['usageTotal']]; break;
				case 'docs': $pieData[] = [$sn, $r['cntDocuments12m']]; break;
				case 'usersAll': $pieData[] = [$sn, $r['cntUsersAll1m']];; break;
			}

			$totals['usageTotal'] += $r['usageTotal'];
			$totals['cntDocuments12m'] += $r['cntDocuments12m'];

			$pks[] = $r['datasource'];
		}

		// -- rest
		$q = [];
		array_push($q, 'SELECT SUM(stats.usageDb) as usageDb, SUM(stats.usageFiles) as usageFiles, SUM(stats.usageTotal) as usageTotal,');
		array_push($q, ' SUM(stats.cntDocuments12m) as cntDocuments12m, SUM(stats.cntUsersAll1m) as cntUsersAll1m');
		array_push($q, ' FROM hosting_core_dsStats AS stats');
		array_push($q, ' LEFT JOIN hosting_core_dataSources AS ds ON stats.dataSource = ds.ndx');
		array_push($q, ' WHERE ds.docState = 4000', ' AND stats.dataSource NOT IN %in', $pks);
		array_push($q, ' AND ds.dsType = %i', 0, ' AND ds.condition = %i', 2);
		if ($this->partner)
			array_push ($q, ' AND ds.[partner] = %i', $this->partner);

		$r = $this->db()->query($q)->fetch();
		if ($r)
		{
			$item = [
					'name' => 'OSTATNÍ',
					'usageDb' => Utils::memf($r['usageDb']), 'usageFiles' => Utils::memf($r['usageFiles']),
					'usageTotal' => Utils::memf($r['usageTotal']),
					'cntDocuments12m' => intval($r['cntDocuments12m']),
					'cntUsersAll1m' => intval($r['cntUsersAll1m'])
			];
			$data[] = $item;

			switch ($orderBy)
			{
				case 'space': $pieData[] = ['OSTATNÍ', $r['usageTotal']]; break;
				case 'docs': $pieData[] = ['OSTATNÍ', $r['cntDocuments12m']]; break;
				case 'usersAll': $pieData[] = ['OSTATNÍ', $r['cntUsersAll1m']]; break;
			}

			$totals['usageTotal'] += $r['usageTotal'];
			$totals['cntDocuments12m'] += $r['cntDocuments12m'];
		}

		// -- content
		switch ($orderBy)
		{
			case 'space': $h = ['#' => '#', 'name' => 'Název', 'usageTotal' => ' Velikost', 'cntDocuments12m' => ' Počet dokladů / 12m']; break;
			case 'docs': $h = ['#' => '#', 'name' => 'Název', 'cntDocuments12m' => ' Počet dokladů / 12m', 'usageTotal' => ' Velikost']; break;
			case 'usersAll': $h = ['#' => '#', 'name' => 'Název', 'cntUsersAll1m' => ' Počet uživatelů', 'usageTotal' => ' Velikost']; break;
		}

		$totalItem = [
			'name' => 'CELKEM',
			'usageTotal' => Utils::memf($totals['usageTotal']),
			'cntDocuments12m' => intval($totals['cntDocuments12m']),
			'_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator']
		];
		$data[] = $totalItem;

		$content = [
				['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $data],
				['pane' => 'e10-pane e10-pane-table', 'type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData]
		];

		return $content;
	}
}

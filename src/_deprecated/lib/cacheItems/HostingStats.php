<?php

namespace lib\cacheItems;

use \e10\utils;


/**
 * Class HostingStats
 * @package lib\cacheItems
 */
class HostingStats extends \Shipard\Base\CacheItem
{
	function createData()
	{
		// -- users
		$usersTotal = $this->getUsers ('all');
		$usersActive = $this->getUsers ('active');
		$usersOnline = $this->getUsers ('online');
		$this->data['users'] = ['all' => $usersTotal, 'active' => $usersActive, 'online' => $usersOnline];

		// -- data sources
		$dsTotal = $this->getDataSources ('all');
		$dsActive = $this->getDataSources ('active');
		$dsOnline = $this->getDataSources ('online');
		$this->data['ds'] = ['all' => $dsTotal, 'active' => $dsActive, 'online' => $dsOnline];

		// -- disk space
		$diskSpaceData = $this->getDiskSpace();
		$this->data['diskSpace'] = ['usageTotal' => $diskSpaceData['usageTotal'], 'usageDb' => $diskSpaceData['usageDb'], 'usageFiles' => $diskSpaceData['usageFiles']];

		$diskSpace = [];
		$diskSpace[] = ['text' => utils::memf($diskSpaceData['usageTotal']), 'icon' => 'icon-hdd-o fa-fw', 'class' => 'e10-widget-big-text'];
		$diskSpace[] = ['text' => utils::memf($diskSpaceData['usageDb']), 'icon' => 'system/iconDatabase', 'class' => 'tag e10-row-this pull-right'];
		$diskSpace[] = ['text' => utils::memf($diskSpaceData['usageFiles']), 'icon' => 'icon-paperclip', 'class' => 'tag e10-row-this pull-right'];

		// -- content
		$pane = ['info' => [], 'class' => 'info'];
		$pane['info'][] = ['class' => 'clear info', 'value' => $ds];
		$pane['info'][] = ['class' => 'clear info', 'value' => $users];
		$pane['info'][] = ['class' => 'clear info', 'value' => $diskSpace];

		parent::createData();
	}

	function getUsers ($type)
	{
		$q[] = 'SELECT COUNT(*) as cnt FROM e10_persons_persons as persons WHERE persons.docState = 4000';
		array_push ($q, ' AND EXISTS (SELECT usersds.user FROM [e10pro_hosting_server_usersds] as usersds');
		array_push ($q, ' WHERE persons.ndx = usersds.user AND usersds.docState = 4000');

		$today = new \DateTime();

		if ($type === 'active')
		{
			$today->sub (new \DateInterval('P3M'));
			array_push ($q, ' AND usersds.lastLogin > %t', $today);
		}
		else
			if ($type === 'online')
			{
				$today->sub (new \DateInterval('PT30M'));
				array_push ($q, ' AND usersds.lastLogin > %t', $today);
			}

		array_push ($q, ' )');

		$c = $this->app->db()->query ($q)->fetch();
		return $c['cnt'];
	}

	function getDataSources ($type)
	{
		$q[] = 'SELECT COUNT(*) as cnt FROM e10pro_hosting_server_datasources as ds WHERE ds.docState = 4000';

		$today = new \DateTime();

		if ($type === 'active')
		{
			$today->sub (new \DateInterval('P3M'));
			array_push ($q, ' AND ds.lastLogin > %t', $today);
		}
		else
			if ($type === 'online')
			{
				$today->sub (new \DateInterval('PT30M'));
				array_push ($q, ' AND ds.lastLogin > %t', $today);
			}

		$c = $this->app->db()->query ($q)->fetch();

		return $c['cnt'];
	}

	function getDiskSpace ()
	{
		$q[] = 'SELECT SUM(stats.usageDb) as usageDb, SUM(stats.usageFiles) as usageFiles, SUM(stats.usageTotal) as usageTotal';
		array_push($q, ' FROM e10pro_hosting_server_datasourcesStats AS stats');
		array_push($q, ' LEFT JOIN e10pro_hosting_server_datasources AS ds ON stats.datasource = ds.ndx');
		array_push($q, ' WHERE ds.docState = 4000');

		$data = $this->app->db()->query ($q)->fetch();
		return $data->toArray();
	}
}

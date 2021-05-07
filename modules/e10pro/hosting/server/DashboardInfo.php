<?php

namespace e10pro\hosting\server;

use \e10\utils;


/**
 * Class DashboardInfo
 * @package e10pro\hosting\server
 */
class DashboardInfo extends \lib\dashboards\Info
{
	public function dailyBar(&$dailyBar)
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.HostingStats');

		$info = ['class' => '', 'content' => []];
		$info['content'][] = [
				'text' => utils::nf($data['data']['ds']['online']), 'suffix' => utils::nf($data['data']['ds']['active']),
				'icon' => 'icon-database', 'class' => ''
		];
		$info['content'][] = [
				'text' => utils::nf($data['data']['users']['online']), 'suffix' => utils::nf($data['data']['users']['active']),
				'icon' => 'icon-user', 'class' => ''
		];
		$info['content'][] = ['text' => utils::memf($data['data']['diskSpace']['usageTotal']), 'icon' => 'icon-hdd-o', 'class' => ''];

		$dailyBar['hosting'] = $info;
	}
}


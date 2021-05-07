<?php

namespace lib\dashboards\widgets;


/**
 * Class BalanceObligations
 * @package lib\dashboards\widgets
 */
class BalanceObligations extends \lib\dashboards\widgets\BalanceCore
{
	public function createContent ()
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.BalanceObligations', TRUE);
		$this->createBalance ('Závazky', 'icon-arrow-circle-down', '', $data);
	}
}

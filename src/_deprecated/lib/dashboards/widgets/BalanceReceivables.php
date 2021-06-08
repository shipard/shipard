<?php

namespace lib\dashboards\widgets;


/**
 * Class BalanceReceivables
 * @package lib\dashboards\widgets
 */
class BalanceReceivables extends \lib\dashboards\widgets\BalanceCore
{
	public function createContent ()
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.BalanceReceivables', TRUE);
		$this->createBalance ('Pohledávky', 'balance/receivables', '', $data);
	}
}

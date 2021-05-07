<?php

namespace lib\cacheItems;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';
require_once __APP_DIR__ . '/e10-modules/e10doc/balance/balance.php';

use \e10doc\balance\reportBalanceReceivables;


/**
 * Class BalanceReceivables
 * @package lib\cacheItems
 */
class BalanceReceivables extends \Shipard\Base\CacheItem
{
	function createData ()
	{
		$report = new reportBalanceReceivables ($this->app);
		$report->subReportId = 'analysis';
		$report->init();
		$report->mode = reportBalanceReceivables::bmNormal;

		$report->prepareData();
		$this->data ['totals'] = $report->totals;

		$this->data ['sum'] = ['request' => 0.0, 'payment' => 0.0, 'rest' => 0.0, 'rest0' => 0.0, 'rest1' => 0.0, 'rest2' => 0.0];

		foreach ($report->totals['subTotal'] as $currencyName => $t)
		{
			$this->data ['sum']['request'] += $t['requestHc'];
			$this->data ['sum']['payment'] += $t['paymentHc'];
			$this->data ['sum']['rest'] += $t['restHc'];
			$this->data ['sum']['rest0'] += $t['rest0'];
			$this->data ['sum']['rest1'] += $t['rest1'];
			$this->data ['sum']['rest2'] += $t['rest2'] + $t['rest3'] + $t['rest4'] + $t['rest5'];
		}

		parent::createData();
	}
}

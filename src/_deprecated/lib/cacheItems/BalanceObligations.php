<?php

namespace lib\cacheItems;

require_once __SHPD_MODULES_DIR__ . 'e10doc/balance/balance.php';

use \e10doc\balance\reportBalanceObligations;


/**
 * Class BalanceObligations
 * @package lib\cacheItems
 */
class BalanceObligations extends \Shipard\Base\CacheItem
{
	function createData ()
	{
		$report = new reportBalanceObligations ($this->app);
		$report->subReportId = 'analysis';
		$report->init();
		$report->mode = reportBalanceObligations::bmNormal;

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

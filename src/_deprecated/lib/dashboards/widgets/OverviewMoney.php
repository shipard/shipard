<?php

namespace lib\dashboards\widgets;

use e10\utils;


/**
 * Class OverviewMoney
 * @package lib\dashboards\widgets
 */
class OverviewMoney extends \lib\dashboards\widgets\BalanceCore
{
	public function createContent ()
	{
		$dataCash = $this->app->cache->getCacheItem('lib.cacheItems.CashStates', TRUE);
		$dataBank = $this->app->cache->getCacheItem('lib.cacheItems.BankAccountsStates', TRUE);

		$amounts = [];
		$amounts[] = ['text' => utils::nf ($dataBank['data']['totalHc']).' ', 'icon' => 'homeBank', 'class' => 'e10-widget-big-text nowrap'];
		$amounts[] = ['text' => utils::nf ($dataCash['data']['totalHc']), 'icon' => 'homeCashbox', 'class' => 'e10-widget-big-text nowrap'];

		$info = [];

		if (isset($dataBank['data']['accounts']) && count ($dataBank['data']['accounts']) > 1)
		{
			foreach ($dataBank['data']['accounts'] as $currId => &$acc)
				$info[] = ['text' => utils::nf($acc['balance']), 'class' => 'nowrap', 'prefix' => $acc['title']];
		}

		if (isset($dataCash['data']['cashBoxes']) && count($dataCash['data']['cashBoxes']) > 1)
		{
			foreach ($dataCash['data']['cashBoxes'] as $currId => &$acc)
				$info[] = ['text' => utils::nf($acc['balance']), 'class' => 'xblock', 'prefix' => $acc['title']];
		}

		$totalHc = $dataBank['data']['totalHc'] + $dataCash['data']['totalHc'];
		$meterData = ['total' => $totalHc, 'parts' => [
			['num' => $dataBank['data']['totalHc'], 'color' => '#AAC', 'title' => 'Banka'],
			['num' => $dataCash['data']['totalHc'], 'color' => '#6AA', 'title' => 'Pokladna'],
		]
		];
		$meter = $this->createCodeMeter($meterData);


		$this->addContent(['type' => 'line', 'line' => ['code' => $meter]]);

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row']);

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-col e10-fx-grow pa1']);
		$this->addContent(['type' => 'line', 'line' => ['text' => 'Peníze', 'icon' => 'homeReportFinance', 'class' => 'e10-widget-big-number nowrap']]);
		$this->addContent(['type' => 'line', 'line' => $amounts, 'openCell' => 'e10-fx-block', 'closeCell' => 1]);
		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		if (count ($info))
			$this->addContent(['type' => 'line', 'line' => $info, 'openCell' => 'e10-fx-col e10-fx-grow align-right pa1', 'closeCell' => 1]);

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
	}
}

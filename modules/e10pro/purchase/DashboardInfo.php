<?php

namespace e10pro\purchase;

use \e10\utils;


/**
 * Class DashboardInfo
 * @package e10pro\purchase
 */
class DashboardInfo extends \lib\dashboards\Info
{
	public function dailyBar(&$dailyBar)
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.PurchasesStates');

		$info = ['class' => '', 'content' => []];

		$i = ['text' => utils::nf($data['data']['toPay']), 'icon' => 'docTypeRedemptions', 'class' => ''];
		if ($data['data']['date'] != utils::today('Y-m-d'))
			$i['suffix'] = utils::datef ($data['data']['date']);
		$info['content'][] = $i;

		$dailyBar['store'] = $info;
	}

	public function dailySummary(&$dailySummary)
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.PurchasesStates', TRUE);

		$table = $data['data']['items'];
		$header = ['title' => 'Položka', 'quantity' => ' Mn.', 'unit' => 'J.', 'taxBaseHc' => ' Cena'];

		$date = (!utils::dateIsBlank($data['date'])) ? utils::createDateTime($data['date']) : utils::today();
		$title = [
				['text' => utils::nf($data['data']['toPay']), 'class' => 'pull-right'],
				['text' => 'Výkupy', 'icon' => 'docTypeRedemptions', 'class' => '', 'suffix' => utils::datef($date, '%k')],
				['text' => utils::nf($data['data']['cntDocs']), 'class' => 'badge badge-success'],
		];

		$info = ['title' => $title, 'table' => $table, 'header' => $header];
		$dailySummary['STORE'] = $info;
	}
}


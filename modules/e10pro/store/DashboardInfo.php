<?php

namespace e10pro\store;

use \e10\utils;


/**
 * Class DashboardInfo
 * @package e10pro\store
 */
class DashboardInfo extends \lib\dashboards\Info
{
	public function dailyBar(&$dailyBar)
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.StoreStates');

		$info = ['class' => '', 'content' => []];

		$i = ['text' => utils::nf($data['data']['sumBaseHc']), 'icon' => 'docType/cashReg', 'class' => ''];
		if ($data['data']['date'] != utils::today('Y-m-d'))
			$i['suffix'] = utils::datef ($data['data']['date']);
		$info['content'][] = $i;

		$dailyBar['store'] = $info;
	}

	public function dailySummary(&$dailySummary)
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.StoreStates', TRUE);

		$table = $data['data']['items'];
		$header = ['title' => 'Položka', 'quantity' => ' Mn.', 'unit' => 'J.', 'taxBaseHc' => ' Cena'];

		$title = [
				['text' => utils::nf($data['data']['sumBaseHc']), 'class' => 'pull-right'],
				['text' => 'Prodejna', 'icon' => 'docType/cashReg', 'class' => ''],
				['text' => utils::nf($data['data']['cntDocs']), 'class' => 'badge badge-success'],
		];

		$info = ['title' => $title, 'paneClass' => 'e10-fx-4-xl', 'table' => $table, 'header' => $header];
		$dailySummary['STORE'] = $info;
	}
}


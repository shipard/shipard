<?php

namespace e10doc\core;

use \e10\utils;


/**
 * Class DashboardInfo
 * @package e10doc\core
 */
class DashboardInfo extends \lib\dashboards\Info
{
	public function dailySummary(&$dailySummary)
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.CompanyDaily');

		foreach (\e10\sortByOneKey($data['data']['docs'], 'order', TRUE) as $docType => $docInfo)
		{
			$items = [];
			$title = [
					['text' => utils::nf($docInfo['baseHc']), 'class' => 'pull-right'],
					['text' => $docInfo['title'], 'icon' => $docInfo['icon'], 'class' => ''],
					['text' => utils::nf($docInfo['count']), 'class' => 'badge badge-success'],
			];

			foreach ($docInfo['docs'] as $r)
			{
				$doc = [];
				$doc[] = ['text' => utils::nf($r['baseHc']), 'class' => 'pull-right'];
				$doc[] = ['text' => $r['personName'], 'class' => 'cce10-small', 'prefix' => $r['ts']];
				//$doc[] = ['text' => $r['personName'], 'class' => 'e10-small'];

				$items[] = $doc;
			}

			$docs = ['title' => $title, 'content' => $items];

			$dailySummary['DOCS_'.$docType] = $docs;
		}

		if (!count($dailySummary))
		{
			$title = [

				['text' => 'Žádné informace pro dnešní den', 'icon' => 'system/iconInfo', 'class' => ''],

			];
			$docs = ['title' => $title, 'content' => []];
			$dailySummary['NOINFO'] = $docs;
		}
	}
}


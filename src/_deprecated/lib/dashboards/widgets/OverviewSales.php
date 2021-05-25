<?php

namespace lib\dashboards\widgets;



/**
 * Class OverviewSales
 */
class OverviewSales extends \Shipard\UI\Core\WidgetPane
{
	public function createContent ()
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.OverviewSales', TRUE);

		$this->addContent (['type' => 'line', 'line' => ['text' => $data['data']['title'], 'icon' => $data['data']['icon'], 'class' => 'e10-widget-big-number']]);

		$this->addContent (['type' => 'graph', 'graphType' => 'bar', 'XKey' => 'period', 'stacked' => 1, 'elementClass' => 'hg30',
			'cullingX' => 0, 'disabledCols' => ['id'], 'graphData' => $data['data']['sales'], 'header' => $data['data']['categories']]);

	}

	public function title()
	{
		return FALSE;
	}
}

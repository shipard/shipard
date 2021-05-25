<?php

namespace lib\dashboards\widgets;


/**
 * Class OverviewCompanyResults
 */
class OverviewCompanyResults extends \Shipard\UI\Core\WidgetPane
{
	public function createContent ()
	{
		$data = $this->app->cache->getCacheItem('lib.cacheItems.OverviewCompanyResults', TRUE);

		$this->addContent (['type' => 'grid', 'cmd' => '']);
		$this->addContent (['type' => 'line', 'line' => ['text' => $data['data']['title'], 'icon' => $data['data']['icon'], 'class' => 'e10-widget-big-number']]);
		$this->addContent (['type' => 'line', 'line' => $data['data']['monthRecapitulation'], 'openCell' => 'padd5 pull-right e10-zebra-even', 'closeCell' => 1]);
		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		$this->addContent (['type' => 'graph', 'graphType' => 'spline', 'graphColors' => ['Náklady' => '#CA0000', 'Výnosy' => '#008C00'], 'XKey' => 'period', 'elementClass' => 'hg30',
			'cullingX' => 0, 'disabledCols' => ['id'], 'graphData' => $data['data']['results'], 'header' => $data['data']['categories']]);
	}

	public function title()
	{
		return FALSE;
	}
}

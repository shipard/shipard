<?php

namespace lib\wkf;


/**
 * Class MapsDashboardPanelsCreator
 * @package lib\wkf
 */
class MapsDashboardPanelsCreator extends \e10\E10Object
{
	public function createDashboardPanels ($dashboardId, &$dashboard, $panelId, &$allWidgets)
	{
		$testMaps = $this->app->cfgItem ('options.experimental.testMaps', 0);
		if (!$testMaps)
			return;

		$order = 20000;
		$maps = $this->app->cfgItem ('e10pro.wkf.maps');
		foreach ($maps as $m)
		{
			if ($dashboardId === 'main' && $m['dashboardMain'] != 9)
				continue;
			if ($dashboardId === 'commerce' && $m['dashboardCommerce'] != 9)
				continue;
			$icon = 'icon-map-o';
			if (isset($m['icon']) && $m['icon'] !== '')
				$icon = $m['icon'];

			$panelId = 'map-'.$m['ndx'];
			$p = [
				'name' => $m['sn'], 'icon' => $icon, 'order' => $order,
				'fullsize' => 1,
				'rows' => ['main' => ['order' => 1], 'class' => 'full']
			];
			$dashboard['panels'][$panelId] = $p;

			$allWidgets[] = [
				'class' => 'lib.wkf.WidgetMap',
				'dashboard' => $dashboardId,
				'panel' => $panelId, 'order' => 1001, 'row' => 'main', 'width' => 12,
				'type' => 'wkfWall e10-widget-dashboard'
			];

			$order++;
		}
	}
}

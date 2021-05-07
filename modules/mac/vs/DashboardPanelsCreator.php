<?php

namespace mac\vs;


/**
 * Class DashboardPanelsCreator
 * @package mac\vs
 */
class DashboardPanelsCreator extends \e10\E10Object
{
	public function createDashboardPanels ($dashboardId, &$dashboard, $panelId, &$allWidgets)
	{
		/** @var \mac\base\TableZones $tableZones */
		$tableZones = $this->app->table ('mac.base.zones');
		$usersZones = $tableZones->usersZones('vs-main');
		$order = isset($dashboard['panels'][$panelId]['order']) ? $dashboard['panels'][$panelId]['order'] : 1800;
		foreach ($usersZones as $z)
		{
			$icon = 'icon-video-camera';
			if (isset($z['icon']) && $z['icon'] !== '')
				$icon = $z['icon'];

			$panelId = 'zone-'.$z['ndx'];
			$p = [
				'name' => $z['sn'], 'icon' => $icon, 'order' => $order,
				'fullsize' => 1,
				'rows' => ['main' => ['order' => 1], 'class' => 'full']
			];
			$dashboard['panels'][$panelId] = $p;

			$allWidgets[] = [
				'class' => 'mac.vs.WidgetLive',
				'dashboard' => $dashboardId,
				'panel' => $panelId, 'order' => 1001, 'row' => 'main', 'width' => 12,
				'type' => 'wkfWall e10-widget-dashboard'
			];

			$order++;
		}
	}
}

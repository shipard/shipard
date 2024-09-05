<?php

namespace e10pro\vendms\libs;


/**
 * class VendMsDashboardPanelsCreator
 */
class VendMsDashboardPanelsCreator extends \e10\E10Object
{
	public function createDashboardPanels ($dashboardId, &$dashboard, $panelId, &$allWidgets)
	{
		$order = isset($dashboard['panels'][$panelId]['order']) ? $dashboard['panels'][$panelId]['order'] : 1800;

		$icon = 'system/iconCutlery';

		$panelId = 'vm-'./*$w['ndx']*/1;
		$p = [
			'name' => /*$w['sn']*/'Automaty', 'icon' => $icon, 'order' => $order,
			'fullsize' => 1,
			'rows' => ['main' => ['order' => 1], 'class' => 'full']
		];
		$dashboard['panels'][$panelId] = $p;

		$allWidgets[] = [
			'class' => 'e10pro.vendms.libs.WidgetVendMs',
			'dashboard' => $dashboardId,
			'panel' => $panelId, 'order' => 1001, 'row' => 'main', 'width' => 12,
			'type' => 'wkfWall e10-widget-dashboard'
		];

		$order++;
	}
}

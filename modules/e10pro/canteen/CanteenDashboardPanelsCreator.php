<?php

namespace e10pro\canteen;


/**
 * Class CanteenDashboardPanelsCreator
 * @package e10pro\canteen
 */
class CanteenDashboardPanelsCreator extends \e10\E10Object
{
	public function createDashboardPanels ($dashboardId, &$dashboard, $panelId, &$allWidgets)
	{
		$tableCanteens = $this->app->table ('e10pro.canteen.canteens');
		$usersCanteens = $tableCanteens->usersCanteens ();
		$order = isset($dashboard['panels'][$panelId]['order']) ? $dashboard['panels'][$panelId]['order'] : 1800;
		foreach ($usersCanteens as $w)
		{
			$icon = 'icon-cutlery';
			if (isset($w['icon']) && $w['icon'] !== '')
				$icon = $w['icon'];

			$panelId = 'canteen-'.$w['ndx'];
			$p = [
				'name' => $w['sn'], 'icon' => $icon, 'order' => $order,
				'fullsize' => 1,
				'rows' => ['main' => ['order' => 1], 'class' => 'full']
			];
			$dashboard['panels'][$panelId] = $p;

			$allWidgets[] = [
				'class' => 'e10pro.canteen.WidgetCanteen',
				'dashboard' => $dashboardId,
				'panel' => $panelId, 'order' => 1001, 'row' => 'main', 'width' => 12,
				'type' => 'wkfWall e10-widget-dashboard'
			];

			$order++;
		}
	}
}

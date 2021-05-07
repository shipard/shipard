<?php

namespace e10pro\kb;


/**
 * Class WidgetWiki
 * @package e10pro\kb
 */
class WikiDashboardPanelsCreator extends \e10\E10Object
{
	public function createDashboardPanels ($dashboardId, &$dashboard, $panelId, &$allWidgets)
	{
		$tableWikies = $this->app->table ('e10pro.kb.wikies');
		$usersWikies = $tableWikies->usersWikies (9);
		$order = isset($dashboard['panels'][$panelId]['order']) ? $dashboard['panels'][$panelId]['order'] : 1800;
		foreach ($usersWikies as $w)
		{

			$icon = 'icon-book';
			if (isset($w['icon']) && $w['icon'] !== '')
				$icon = $w['icon'];

			$panelId = 'wiki-'.$w['ndx'];
			$p = [
				'name' => $w['sn'], 'icon' => $icon, 'order' => $order,
				'fullsize' => 1,
				'rows' => ['main' => ['order' => 1], 'class' => 'full']
			];
			$dashboard['panels'][$panelId] = $p;

			$allWidgets[] = [
				'class' => 'e10pro.kb.WidgetWiki', 
				'dashboard' => $dashboardId,
				'panel' => $panelId, 'order' => 1001, 'row' => 'main', 'width' => 12,
				'type' => 'wkfWall e10-widget-dashboard'
			];

			$order++;
		}
	}
}

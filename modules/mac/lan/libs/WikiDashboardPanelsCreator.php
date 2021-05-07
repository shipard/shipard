<?php

namespace mac\lan\libs;


/**
 * Class WikiDashboardPanelsCreator
 * @package mac\lan\libs
 */
class WikiDashboardPanelsCreator extends \e10\E10Object
{
	public function createDashboardPanels ($dashboardId, &$dashboard, $panelId, &$allWidgets)
	{
		if (!$this->app()->hasRole('maclan'))
			return;
		$useDocumentation = intval($this->app()->cfgItem ('options.macLAN.useDocumentation', 0));
		if (!$useDocumentation)
			return;

		/** @var $tableWikies \e10pro\kb\TableWikies */
		$tableWikies = $this->app->table ('e10pro.kb.wikies');
		$usersWikies = $tableWikies->usersWikies ();

		$itWikies = [];
		$itWikiesRows = $this->db()->query('SELECT wiki FROM [mac_lan_lans] WHERE [wiki] != %i', 0, ' AND [docState] != %i', 9800);
		foreach ($itWikiesRows as $r)
		{
			if (!in_array($r['wiki'], $itWikies))
				$itWikies[] = $r['wiki'];
		}

		$order = isset($dashboard['panels'][$panelId]['order']) ? $dashboard['panels'][$panelId]['order'] : 1800;
		foreach ($itWikies as $wikiNdx)
		{
			$w = $usersWikies[$wikiNdx];

			$icon = 'icon-book';
			//if (isset($w['icon']) && $w['icon'] !== '')
			//	$icon = $w['icon'];

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

<?php


namespace lib\workOrders;
use \Shipard\UI\Core\WidgetBoard;


/**
 * Class WorkOrdersWallWidget
 * @package lib\workOrders
 */
class WorkOrdersWallWidget extends WidgetBoard
{
	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if ($this->activeTopTab === 'workOrders')
		{
			$this->addContentViewer('e10mnf.core.workOrders', 'lib.workOrders.ViewerDashboardWorkOrders', ['viewerMode' => $viewerMode]);
		}
		elseif (substr ($this->activeTopTab, 0, 4) === 'wog-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$this->addContentViewer('e10mnf.core.workOrders', 'lib.workOrders.ViewerDashboardWorkOrders', ['workOrderGroup' => $parts[1], 'viewerMode' => $viewerMode]);
		}
		elseif (substr ($this->activeTopTab, 0, 4) === 'map-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$this->composeCodeMap(intval($parts[1]));
		}
	}

	public function composeCodeMap($mapNdx)
	{
		$w = new \lib\wkf\WidgetMap($this->app);
		$w->mapNdx = $mapNdx;
		$w->init();
		$w->createContent();

		$this->addContent($w->content[0]);
	}


	public function init ()
	{
		$this->createTabs();

		parent::init();
	}

	function addWorkOrdersGroupsTabs (&$tabs)
	{
		$woGroups = $this->app->cfgItem('e10mnf.base.workOrdersGroups');

		if (!count($woGroups))
		{
			$icon = 'icon-industry';
			$wgNdx = 1;

			$tabs['wog-'.$wgNdx] = ['icon' => $icon, 'text' => 'Zakázky', 'action' => 'load-wog-'.$wgNdx];
			return;
		}

		foreach ($woGroups as $wgNdx => $wg)
		{
			$icon = 'icon-industry';
			if ($wg['icon'] !== '')
				$icon = $wg['icon'];

			$tabs['wog-'.$wgNdx] = ['icon' => $icon, 'text' => $wg['sn'], 'action' => 'load-wog-'.$wgNdx];
		}
	}

	function addMapsTabs (&$tabs)
	{
		$testMaps = $this->app->cfgItem ('options.experimental.testMaps', 0);
		if (!$testMaps)
			return;

		$maps = $this->app->cfgItem ('e10pro.wkf.maps');
		foreach ($maps as $m)
		{
			if ($m['dashboardCommerce'] != 1)
				continue;

			$icon = 'icon-map-o';
			if (isset($m['icon']) && $m['icon'] !== '')
				$icon = $m['icon'];
			$tabs['map-'.$m['ndx']] = ['icon' => $icon, 'text' => $m['sn'], 'action' => 'load-map-'.$m['ndx']];
		}
	}

	function createTabs ()
	{
		$tabs = [];

		//$tabs['workOrders'] = ['icon' => 'system/iconPinned', 'text' => 'Zakázky', 'action' => 'load-workOrders'];
		$this->addWorkOrdersGroupsTabs ($tabs);
		$this->addMapsTabs($tabs);

		$this->toolbar = ['tabs' => $tabs];

		$rt = [
				'viewer-mode-2' => ['text' =>'', 'icon' => 'icon-th', 'action' => 'viewer-mode-2'],
				'viewer-mode-1' => ['text' =>'', 'icon' => 'icon-th-list', 'action' => 'viewer-mode-1'],
				'viewer-mode-3' => ['text' =>'', 'icon' => 'icon-square', 'action' => 'viewer-mode-3'],
				'viewer-mode-0' => ['text' =>'', 'icon' => 'icon-th-large', 'action' => 'viewer-mode-0'],
			];

		$this->toolbar['rightTabs'] = $rt;
	}

	public function title()
	{
		return FALSE;
	}
}

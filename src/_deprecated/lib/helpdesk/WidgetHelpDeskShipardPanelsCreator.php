<?php

namespace lib\helpdesk;


/**
 * class WidgetHelpDeskShipardPanelsCreator
 */
class WidgetHelpDeskShipardPanelsCreator extends \Shipard\Base\BaseObject
{
	public function createDashboardPanels ($dashboardId, &$dashboard, $panelId, &$allWidgets)
	{
    $helpDeskMode = intval($this->app()->cfgItem('dsi.helpdeskMode', 0));
    if (!$helpDeskMode)
      return;

		$order = isset($dashboard['panels'][$panelId]['order']) ? $dashboard['panels'][$panelId]['order'] : 800100;
    $icon = 'system/rightSubmenuSupport';
    $panelId = 'helpdeskShipard1';

    $p = [
      'name' => 'Helpdesk Shipard', 'icon' => $icon, 'order' => $order,
      'fullsize' => 1,
      'rows' => ['main' => ['order' => 1], 'class' => 'full'],
      "ntfBadgeId" => "ntf-badge-hhdsk-total",
    ];
    $dashboard['panels'][$panelId] = $p;

    $allWidgets[] = [
      'class' => 'lib.helpdesk.WidgetHelpDeskShipard',
      'dashboard' => $dashboardId,
      'panel' => $panelId,
      'order' => 1001, 'row' => 'main', 'width' => 12,
      'type' => 'wkfWall e10-widget-iframe'
    ];
	}
}


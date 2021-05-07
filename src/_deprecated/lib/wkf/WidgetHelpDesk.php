<?php

namespace lib\wkf;


/**
 * Class WidgetHelpDesk
 * @package lib\wkf
 */
class WidgetHelpDesk extends \lib\wkf\WidgetIframe
{
	public function init()
	{
		$sectionNdx = 0;

		$dsi = $this->app()->cfgItem ('dsi');
		if (isset($dsi['supportSection']) && $dsi['supportSection'])
			$sectionNdx = $dsi['supportSection'];

		$helpDeskUrl = 'https://' . 'system.shipard.app';

		if ($sectionNdx)
			$this->url = $helpDeskUrl."/app/!/widget/viewer/wkf.core.issues/wkf.core.viewers.DashboardIssuesSection;fixedSection:$sectionNdx?mainWidgetMode=1&disableAppRightMenu=1";
		//else
		//	$this->url = $helpDeskUrl."/app/!/widget/dashboard/shipard-app/support/?disableAppMenu=1&disableLeftMenu=1&disableAppRightMenu=1&mainWidgetMode=1&widgetPanelId=support";

		parent::init();
	}
}

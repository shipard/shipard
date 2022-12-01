<?php

namespace lib\helpdesk;


/**
 * class WidgetHelpDeskShipard
 */
class WidgetHelpDeskShipard extends \lib\wkf\WidgetIframe
{
	public function init()
	{
		$dsId = $this->app->cfgItem('dsid');
		$helpDeskUrl = 'https://' . 'shipard.app';
		$this->url = $helpDeskUrl."/app/!/widget/viewer/helpdesk.core.tickets/hosting.core.libs.ViewerHelpdeskRemote;dsId:{$dsId}?mainWidgetMode=1&disableAppRightMenu=1&dsId=".$dsId;

		parent::init();
	}
}

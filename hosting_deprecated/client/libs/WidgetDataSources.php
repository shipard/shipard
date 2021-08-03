<?php

namespace e10pro\hosting\client\libs;


use \e10\widgetBoard;


/**
 * Class WidgetDataSources
 * @package e10pro\hosting\client\libs
 */
class WidgetDataSources extends widgetBoard
{
	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$this->addContentViewer('e10pro.hosting.server.datasources', 'e10pro.hosting.client.libs.DataSourcesDashboardViewer', ['widgetId' => $this->widgetId]);
	}

	public function title()
	{
		return FALSE;
	}
}


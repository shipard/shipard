<?php


namespace lib\wkf;


/**
 * Class WidgetMap
 * @package lib\wkf
 */
class WidgetMap extends \e10\widgetPane
{
	var $mapNdx = 0;

	public function createContent ()
	{
		if ($this->mapNdx === 0)
		{
			$panelId = $this->app->testGetParam('widgetPanelId');
			$parts = explode('-', $panelId);
			if (count($parts) === 2 && $parts[0] === 'map')
				$this->mapNdx = intval($parts[1]);
		}

		$this->addContent(['type' => 'map', 'map' => ['mapDefId' => $this->mapNdx]]);
	}
}

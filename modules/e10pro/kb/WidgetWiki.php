<?php

namespace e10pro\kb;


/**
 * Class WidgetWiki
 * @package e10pro\kb
 */
class WidgetWiki extends \lib\wkf\WidgetIframe
{
	var $wikiNdx = 0;

	public function init()
	{
		if ($this->wikiNdx === 0)
		{
			$panelId = $this->app->testGetParam('widgetPanelId');
			$parts = explode('-', $panelId);
			if (count($parts) === 2 && $parts[0] === 'wiki')
				$this->wikiNdx = intval($parts[1]);
		}

		$this->url = 'https://'.$_SERVER['HTTP_HOST'].$this->app->dsRoot."/app/wiki-{$this->wikiNdx}/";
		parent::init();
	}
}

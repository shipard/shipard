<?php

namespace lib\web;


/**
 * Class WebWidgetPreviewOne
 * @package e10pro\kb
 */
class WebWidgetPreviewOne extends \lib\wkf\WidgetIframe
{
	var $serverNdx = 0;
	var $forceUrl = '';

	public function init()
	{
		$serverInfo = $this->app->cfgItem('e10.web.servers.list.'.$this->serverNdx);

		if ($this->forceUrl !== '')
			$this->url = $this->forceUrl;
		else
			$this->url = $this->app->urlProtocol . $_SERVER['HTTP_HOST'].$this->app->dsRoot . '/www/'.$serverInfo['urlStartSec'].'/';

		parent::init();
	}
}

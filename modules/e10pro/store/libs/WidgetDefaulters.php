<?php

namespace e10pro\store\libs;

class WidgetDefaulters extends \lib\wkf\WidgetIframe
{
	public function init()
	{
		$this->url = 'https://'.$_SERVER['HTTP_HOST'].$this->app->dsRoot."/mapp/!/start.defaulters";
		parent::init();
	}
}

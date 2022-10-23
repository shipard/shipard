<?php

namespace e10pro\store\libs;

class WidgetCashRegister extends \lib\wkf\WidgetIframe
{
	public function init()
	{
		$this->url = 'https://'.$_SERVER['HTTP_HOST'].$this->app->dsRoot."/mapp/!/widget/terminals.store.WidgetCashBox";
		parent::init();
	}
}

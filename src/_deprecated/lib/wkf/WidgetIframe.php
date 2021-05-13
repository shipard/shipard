<?php


namespace lib\wkf;

use \Shipard\UI\Core\WidgetPane;


/**
 * Class WidgetIframe
 * @package lib\wkf
 */
class WidgetIframe extends WidgetPane
{
	var $url = '';
	var $deviceKind = '';

	public function init()
	{
		$this->widgetMainClass = 'e10-widget-iframe';
		parent::init();
	}

	public function createContent()
	{
		if ($this->url === '')
			return;

		$url = $this->url;
		$class = 'e10-iframe-widget';
		if ($this->deviceKind !== '')
			$class .= ' e10-iframe-widget-'.$this->deviceKind;

		$c = '';
		$c .= "<iframe id='{$this->widgetId}_iframe' src='$url' frameborder='0' style='height:100%; height: calc(100% - 3px);overflow: hidden;' class='$class'></iframe>";
		$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}
}

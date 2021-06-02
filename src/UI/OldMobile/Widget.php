<?php

namespace Shipard\UI\OldMobile;


/**
 * Class Widget
 * @package ui\mobile
 */
class Widget extends \Shipard\UI\OldMobile\PageObject
{
	var $widgetClass;
	var $widget = NULL;

	public function createContent ()
	{
		$this->widgetClass = $this->app->requestPath(2);
		$this->widget = $this->app->createObject($this->widgetClass);
		$this->widget->setDefinition($this->widgetClass);
		$this->widget->init();
		$this->widget->createContent();
	}

	public function createContentCodeInside ()
	{
		$c = '';

		if (!$this->widget)
			return '';
		if (!$this->widget->checkAccess ($this->widgetClass))
			return 'access denied';

		if (!$this->widget->isBlank())
		{
			//$c .= "<div class='e10-widget-pane e10-widget-infoSmall' id='{$widget->widgetId}' data-widget-class='{$widgetClass}'>";
			$c .= $this->widget->renderContent(TRUE);
			//$c .= '</div>';
		}

		return $c;
	}

	public function createPageCodeTitle ()
	{
		if (!$this->widget->fullScreen())
			return parent::createPageCodeTitle();

		return '';
	}

	public function title1 ()
	{
		return $this->widget->titleMobile();
	}

	public function leftPageHeaderButton ()
	{
		$lmb = ['icon' => PageObject::backIcon, 'path' => '#start', 'backButton' => 1];
		return $lmb;
	}

	public function pageType ()
	{
		return $this->widget->pageType();
	}
}

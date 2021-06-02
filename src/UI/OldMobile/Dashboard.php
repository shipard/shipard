<?php

namespace ui\mobile;


/**
 * Class Dashboard
 * @package mobileui
 */
class Dashboard extends \ui\mobile\PageObject
{
	var $viewer = NULL;

	public function createContent ()
	{
	}

	public function createContentCodeInside ()
	{
		$c = '';

		$widgets = $this->app->cfgItem('dashboards.mobile.'.$this->definition['dashboard']);
		foreach ($widgets as $w)
		{
			$widgetClass = $w['class'];

			$widget = $this->app->createObject($widgetClass);
			if (!$widget)
				continue;
			if (!$widget->checkAccess ($widgetClass))
				continue;

			$widget->setDefinition($widgetClass);
			$widget->init();
			$widget->createContent();
			if (!$widget->isBlank())
			{
				$c .= "<div class='e10-gs-col e10-gs-col12'>";
				//$c .= "<div class='e10-widget-pane e10-widget-infoSmall' id='{$widget->widgetId}' data-widget-class='{$widgetClass}'>";
				$c .= $widget->renderContent(TRUE);
				//$c .= '</div>';
				$c .= '</div>';
			}
		}
		return $c;
	}

	public function createContentCodeBegin ()
	{
		$c = "<div class='e10-gs-row full' id='e10dashboardWidget'>";
		return $c;
	}

	public function createContentCodeEnd ()
	{
		$c = '</div>';
		return $c;
	}

	public function title1 ()
	{
		return $this->definition['t1'];
	}

	public function leftPageHeaderButton ()
	{
		$parts = explode ('.', $this->definition['itemId']);
		$lmb = ['icon' => PageObject::backIcon, 'path' => '#'.$parts['0'], 'backButton' => 1];
		return $lmb;
	}
}

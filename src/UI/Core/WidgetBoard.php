<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;


class WidgetBoard extends \Shipard\UI\Core\WidgetPane
{
	const psNone = 0, psFloat = 1, psFixed = 2;

	var $activeTopTab = '';
	var $activeTopTabRight = '';
	var $toolbar = NULL;
	var $panelStyle = self::psFloat;
	var $panelWidth = 'e10-1x';

	public function init()
	{
		$this->widgetMainClass = 'e10-widget-board';
		parent::init();

		$this->activeTopTab = $this->app->testGetParam('e10-widget-topTab');
		if ($this->activeTopTab === '')
			$this->activeTopTab = $this->app->testPostParam('e10-widget-topTab', '');

		if ($this->toolbar)
		{
			if ($this->activeTopTab === '')
				$this->activeTopTab = key($this->toolbar['tabs']);

			$this->initRightTabs();

			if (isset($this->toolbar['rightTabs']))
			{
				$this->activeTopTabRight = $this->app->testGetParam('e10-widget-topTab-right');

				if ($this->activeTopTabRight === '')
					$this->activeTopTabRight = $this->app->testPostParam('e10-widget-topTab-right', '');

				if ($this->activeTopTabRight === '' || !isset($this->toolbar['rightTabs'][$this->activeTopTabRight]))
					$this->activeTopTabRight = key($this->toolbar['rightTabs']);
			}
		}
	}

	protected function initRightTabs()
	{
	}

	protected function renderContentContent($fullCode)
	{
		$cr = new ContentRenderer ($this->app);
		$cr->setWidget($this);

		$c = '';

		if ($fullCode)
		{
			$c .= $this->renderContentTitle();

			$c .= "<div class='e10-widget-content e10-widget-board e10-widget-" . $this->widgetType() . "'>";
				$c .= "<div class='e10-wr-data'>";
				$c .= $cr->createCode();
				$c .= "</div>";

				if ($this->panelStyle != self::psNone)
				{
					if ($this->panelStyle === self::psFloat)
					{
						$c .= "<div class='e10-wr-params close'>";
						$c .= "<div class='tlbr e10-reportPanel-toggle'><i class='fa fa-bars'></i></div>";
					}
					else
						$c .= "<div class='e10-wr-params {$this->panelWidth} fixed'>";
					$c .= "<div class='params' id='e10-widget-panel'>";
					$c .= $this->createPanelCode();
					$c .= "</div>";
					$c .= "</div>";
				}
			$c .= '</div>';
		}
		else
			$c .= $cr->createCode();
		return $c;
	}

	function renderContentTitle ()
	{
		if (!$this->toolbar)
			return parent::renderContentTitle();

		$c = '';

		$tabsClass = 'e10-wf-tabs';
		if (!count ($this->toolbar['tabs']))
			$tabsClass .= ' e10-wf-tabs-inside-viewer';

		$c .= "<div class='$tabsClass'>";
		foreach ($this->toolbar as $key => $obj)
		{
			if ($key === 'tabs')
			{
				$c .= "<input type='hidden' name='e10-widget-topTab' id='e10-widget-topTab-value' value='{$this->activeTopTab}'>";
				$c .= "<ul class='e10-wf-tabs left'>";

				if (isset($this->toolbar['logo']))
				{
					$c .=  "<li id='e10-mm-button' class='tab' style='cursor: pointer; padding: .2ex .5ex .2ex 1ex;'><i class='fa fa-th'></i></li>";
					$logoUrl = $this->app->dsRoot.$this->toolbar['logo'];
					$c .= "<li class='e10-panel-logo' style='text-align: left; padding: 0 1.6ex;'><a href='{$this->app->urlRoot}/'><img style='height: 1.7em;' src='$logoUrl'/></a></li>";
				}
				elseif (isset($this->toolbar['logoUrl']))
				{
					$logoUrl = $this->toolbar['logoUrl'];
					$c .= "<li class='e10-panel-logo' style='text-align: left; padding: 0 1.6ex;'><a href='{$this->app->urlRoot}/'><img style='height: 1.7em;' src='$logoUrl'/></a></li>";
				}
				elseif (isset($this->toolbar['logos']))
				{
					foreach ($this->toolbar['logos'] as $logoUrl)
					{
						$c .= "<li class='e10-panel-logo' style='text-align: left; padding: 0 1.6ex;'>";
						$c .= "<img style='height: 1.7em;' src='$logoUrl'/>";
						$c .= "</li>";
					}
				}

				foreach ($this->toolbar['tabs'] as $tabId => $tab)
				{
					$tabParams = '';
					if (isset($tab['title']))
						$tabParams = ' title="'.utils::es($tab['title']).'"';
					$active = ($this->activeTopTab === $tabId) ? ' active' : '';

					if (isset($tab['action']))
						$c .= "<li class='tab e10-widget-trigger{$active}' data-action='{$tab['action']}' data-tabid='{$tabId}'$tabParams>";
					else
						$c .= "<li>";

					if (isset($tab['line']))
						$c .= '<span>'.$this->app()->ui()->composeTextLine($tab['line']).'</span>';
					else
					{
						$c .= $this->app()->ui()->icon($tab['icon']);
						if ($tab['text'] !== '')
							$c .= '<span>' . '&nbsp;' . utils::es($tab['text']) . '</span>';
					}

					if (isset($tab['ntfBadgeId']))
						$c .= "<span class='e10-ntf-badge' id='{$tab['ntfBadgeId']}' style='display:none; left: auto;'></span>";

					$c .= '</li>';
				}
				$c .= '</ul>';
			}

			if ($key === 'rightTabs')
			{
				$c .= "<input type='hidden' name='e10-widget-topTab-right' id='e10-widget-topTab-value-right' value='{$this->activeTopTabRight}'>";
				$c .= "<ul class='e10-wf-tabs right'>";
				foreach ($this->toolbar['rightTabs'] as $tabId => $tab)
				{
					$active = ($this->activeTopTabRight === $tabId) ? ' active' : '';
					$icon = $this->app()->ui()->icon($tab['icon']);
					$c .= "<li class='tab e10-widget-trigger{$active}' data-action='{$tab['action']}' data-tabid='{$tabId}'>$icon".utils::es($tab['text']) . '</li>';
				}
				$c .= '</ul>';
			}

			if ($key === 'buttons')
			{
				$c .= "<ul class='e10-wf-tabs' style='float:right;'>";
				foreach ($this->toolbar['buttons'] as $b)
				{
					if ((isset($b['element']) && $b['element'] === 'li') || (isset($b['type']) && $b['type'] === 'li'))
						$c .= $this->app()->ui()->composeTextLine($b);
					else
					{
						$c .= "<li>";
						$c .= $this->app()->ui()->composeTextLine($b);
						$c .= "</li>";
					}
				}
				$c .= "</ul>";
			}
		}
		$c .= '</div>';

		return $c;
	}

	public function createPanelCode ()
	{
		$c = '';

		forEach ($this->params->getParams() as $paramId => $paramContent)
		{
			if (!isset ($paramContent['options']['place']))
				continue;
			$c .= $this->params->createParamCode ($paramId).'<br/>';
		}
		return $c;
	}
}

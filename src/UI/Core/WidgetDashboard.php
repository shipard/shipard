<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;



class WidgetDashboard extends \Shipard\UI\Core\Widget
{
	var $allWidgets;
	var $dashboard;
	var $dashboardId;
	var $panelId = 'main';
	var $panel;

	var $widgetDefs = [];
	var $widgetMatrix = [];

	var $mainWidget = NULL;

	public function createToolbarCode ()
	{
		$c = '';
		if ($this->mainWidget === NULL)
			return $c;

		forEach ($this->mainWidget->params->getParams() as $paramId => $paramContent)
		{
			if (isset ($paramContent['options']['place']))
				continue;
			$c .= $this->mainWidget->params->createParamCode ($paramId);
		}

		$c .= "<span style='padding-left: 3em;'>&nbsp;</span> ";


		$buttons = $this->mainWidget->createToolbar ();
		forEach ($buttons as $btn)
			$c .= ' '.$this->app()->ui()->actionCode($btn);

		return $c;
	}

	function createDashboardPanels (&$dashboard)
	{
		$toUnset = [];
		foreach ($dashboard['panels'] as $panelId => $panel)
		{
			if (!isset($panel['creatorClass']))
				continue;

			$c = $this->app->createObject ($panel['creatorClass']);
			if (!$c)
				continue;

			$c->createDashboardPanels ($this->dashboardId, $dashboard, $panelId, $this->allWidgets);
			$toUnset[] = $panelId;
		}
		foreach ($toUnset as $key)
			unset ($dashboard['panels'][$key]);
	}

	public function createWidgetList ()
	{
		$this->allWidgets = $this->app->cfgItem ('widgets');
		$this->dashboardId = $this->app->testGetParam ('subclass');
		$this->dashboard = $this->app->cfgItem ('dashboards.'.$this->dashboardId);

		$this->createDashboardPanels($this->dashboard);

		$this->panelId = $this->app->testGetParam('widgetPanelId');
		if ($this->panelId === '')
			$this->panelId = $this->subWidgets()[0]['id'];
		$this->panel = $this->dashboard['panels'][$this->panelId];

		if (isset($this->dashboard['disableTopToolbar']))
			$this->objectData ['disableTopToolbar'] = $this->dashboard['disableTopToolbar'];

		forEach ($this->allWidgets as $w)
		{
			if (!isset ($w ['dashboard']) || $w ['dashboard'] !== $this->dashboardId)
				continue;
			if (!isset ($w ['panel']) || $w ['panel'] !== $this->panelId)
				continue;
			if (!$this->checkAccess ($w['class']))
				continue;

			$widgetRow = $w['row'];
			if (!isset ($this->widgetDefs[$widgetRow]))
				$this->widgetDefs[$widgetRow] = ['order' => $this->dashboard['panels'][$this->panelId]['rows'][$widgetRow]['order'], 'widgets' => []];

			$this->widgetDefs[$widgetRow]['widgets'][] = ['order' => $w['order'], 'def' => $w, 'code' => NULL];
		}

		// -- create code
		$rowIds = [];
		foreach ($this->widgetDefs as $rowId => $rowWidgets)
		{
			$rowIds [] = $rowId;
			foreach ($rowWidgets['widgets'] as $wid => $widgetDef)
			{
				$widget = $this->app->createObject ($widgetDef['def']['class']);
				$widget->setDefinition ($widgetDef['def']);
				$widget->init();
				$widget->createContent();
				if (!$widget->isBlank())
				{
					$this->widgetDefs[$rowId]['widgets'][$wid]['code'] = $widget->renderContent();
					if ($widget->isBlank())
						$this->widgetDefs[$rowId]['widgets'][$wid]['code'] = NULL;
				}
				if (isset ($widgetDef['def']['type']) && $widgetDef['def']['type'])
					$this->mainWidget = $widget;
				else
					unset ($widget);
			}
		}

		// -- order
		$this->widgetDefs = \E10\sortByOneKey ($this->widgetDefs, 'order', TRUE);
		foreach ($rowIds as $rid)
			$this->widgetDefs[$rid]['widgets'] = \E10\sortByOneKey ($this->widgetDefs[$rid]['widgets'], 'order', TRUE, TRUE, 'code');


		// -- create matrix
		foreach ($this->widgetDefs as $rowId => $rowWidgets)
		{
			$rowDef = $this->dashboard['panels'][$this->panelId]['rows'][$rowId];
			$totalWidth = 0;
			$newRow = [];
			foreach ($rowWidgets['widgets'] as $widgetDef)
			{
				if ($totalWidth + $widgetDef['def']['width'] > 12)
				{
					$this->widgetMatrix [] = [
						'rowId' => $rowId,
						'align' => (isset($rowDef['align'])) ? $rowDef['align'] : '',
						'class' => (isset($rowDef['class'])) ? $rowDef['class'] : '',
						'widgets' => $newRow];
					$newRow = [];
					$totalWidth = 0;
				}
				$newRow [] = [
					'def' => $widgetDef['def'],
					'width' => $widgetDef['def']['width'],
					'maxwidth' => (isset($widgetDef['def']['maxwidth'])) ? $widgetDef['def']['maxwidth'] : $widgetDef['def']['width'],
					'code' => $widgetDef['code']
				];
				$totalWidth += $widgetDef['def']['width'];
			}
			if (count ($newRow))
				$this->widgetMatrix [] = [
					'rowId' => $rowId,
					'align' => (isset($rowDef['align'])) ? $rowDef['align'] : '',
					'class' => (isset($rowDef['class'])) ? $rowDef['class'] : '',
					'fluidColumns' => (isset($rowDef['fluidColumns'])) ? $rowDef['class'] : 0,
					'widgets' => $newRow];
		}

		// -- set widths
		foreach ($this->widgetMatrix as &$row)
			$this->setWidths($row);
	}

	public function setWidths (&$row)
	{
		if (isset($row['fluidColumns']))
			return;

		$currentWidth = 0;
		foreach ($row ['widgets'] as $w)
			$currentWidth += $w['width'];
		while ($currentWidth < 12)
		{
			$change = 0;
			foreach ($row ['widgets'] as $wid => $w)
			{
				if ($currentWidth >= 12)
					break;
				if ($row['widgets'][$wid]['width'] < $row['widgets'][$wid]['maxwidth'])
				{
					$row['widgets'][$wid]['width']++;
					$currentWidth++;
					$change++;
				}
			}
			if ($change === 0)
				break;
		}

		if ($currentWidth < 12)
		{
			if ($row['align'] === 'right')
			{
				foreach ($row['widgets'] as &$w)
				{
					if (isset ($w['def']['align']) && $w['def']['align'] === 'left')
						continue;
					$w['offset'] = 12 - $currentWidth;
					break;
					//$row['widgets'][0]['offset'] = 12 - $currentWidth;
				}
			}
		}
	}

	public function createMainCode ()
	{
		$this->createWidgetList();

		$mainClass = 'e10-wdb';
		$fullSize = (isset ($this->panel['fullsize']) && $this->panel['fullsize']);
		if ($fullSize)
			$mainClass .= ' e10-wdb-fs';
		$c = '';
		$c .= "<div class='$mainClass' id='e10dashboardWidget' style='width: 100%;'>";

		forEach ($this->widgetMatrix as $row)
		{
			if (!$fullSize)
			{
				$class = '';
				if ($row['class'] !== '')
					$class .= ' '.$row['class'];
				$c .= "<div class='e10-gs-row{$class}'>";
			}
			foreach ($row['widgets'] as $widget)
			{
				if ($fullSize)
				{
					$c .= $widget['code'];
					continue;
				}

				$class = "e10-gs-col e10-gs-col{$widget['width']}";
				if (isset ($widget['offset']) && $widget['offset'])
					$class .= " e10-gs-offset{$widget['offset']}";
				$c .= "<div class='$class'>";
				$c .= $widget['code'];
				$c .= '</div>';
			}
			if (!$fullSize)
				$c .= '</div>';
		}
		$c .= "</div>";

		return $c;
	}

	public function subWidgets ()
	{
		$d = [];
		$panels = \e10\sortByOneKey($this->dashboard['panels'], 'order', TRUE);

		forEach ($this->allWidgets as $w)
		{
			if (!isset ($w ['dashboard']) || $w ['dashboard'] !== $this->dashboardId || !isset ($w ['panel']))
				continue;
			if (!isset($this->dashboard['panels'][$w ['panel']]))
				continue;
			if (!$this->checkAccess ($w['class']))
				continue;
			if (!utils::enabledCfgItem ($this->app, $w))
				continue;
			$panels[$w ['panel']]['enabled'] = 1;
		}

		foreach ($panels as $panelId => $panel)
		{
			if (!isset($panels[$panelId]['enabled']))
				continue;
			if (!utils::enabledCfgItem ($this->app, $panel))
				continue;
			$t = ['id' => $panelId, 'icon' => $panel['icon'], 'title' => $panel['name']];
			if (isset($panel['remote']))
				$t['remote'] = $panel['remote'];
			if (isset($panel['ntfBadgeId']))
				$t['ntfBadgeId'] = $panel['ntfBadgeId'];

			$d[] = $t;
		}
		//if (count($d) === 1)
		//	return [];
		return $d;
	}
}

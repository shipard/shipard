<?php

namespace Shipard\Report;
use \Shipard\Utils\Utils;



class WidgetReports extends \E10\Widget
{
	public function createMainCode ()
	{
		$group = $this->app->testGetParam ('subclass');
		$reports = $this->app->cfgItem ('reports');
		$subType = $this->app->testGetParam ('subtype');

		$this->objectData['widgetData'] = [];

		$widgetClass = '';
		if ($subType === 'oneReport')
			$widgetClass = ' report-only';

		$c = '';
		$c .= "<div class='e10-wr$widgetClass' id='e10reportWidget' style='width: 100%;'>";

		$c .= "<div class='e10-wr-left'>";
		$c .= "<ul class='e10-wr-listreps e10-selectReport' style=''>";
		$autoSelect = FALSE;
		if ($subType === 'oneReport')
		{
			$reportClass = $group;
			$reportDef = Utils::searchArray($reports, 'class', $reportClass);
			if ($reportDef)
			{
				$icon = 'fa fa-file-o';
				if (isset($reportDef['icon']))
					$icon = $this->app()->ui()->icons()->cssClass($reportDef['icon']);

				$autoSelect = TRUE;
				$c .= "<li class='e10-wr-repsel auto' data-class='{$reportClass}'><i class='" . $icon . "'></i> " . Utils::es($reportDef ['name']) . "</li>";
			}
		}
		else
		{
			$reportsByGroup = array();
			forEach ($reports as $r)
			{
				if ($r ['group'] != $group)
					continue;
				$order = 0;
				if (isset($r['order']))
					$order = $r['order'];
				$reportsByGroup[$order][] = $r;
			}
			if (count($reportsByGroup) > 1)
				ksort($reportsByGroup, SORT_REGULAR);

			forEach ($reportsByGroup as $key => $order)
			{
				foreach ($order as $r)
				{
					$auto = '';
					if (isset ($r['autoselect']))
					{
						$auto = ' auto';
						$autoSelect = TRUE;
					}
					if (!$this->app->checkAccess(['object' => 'report', 'class' => $r ['class']]))
						continue;
					$icon = 'fa fa-file-o';
					if (isset($r['icon']))
						$icon = $this->app()->ui()->icons()->cssClass($r['icon']);
					$c .= "<li class='e10-wr-repsel{$auto}' data-class='{$r ['class']}'><i class='" . $icon . "'></i> " . \E10\es($r ['name']) . "</li>";
					$this->objectData['widgetData'][] = [
						'icon' => isset($r['icon']) ? $r['icon'] : 'icon-file-o',
						'name' => $r['name'],
						'reportClass' => $r['class']
					];
				}
			}
		}
		$c .= '</ul>';
		$c .= '</div>';

		$c .= "<div class='e10-wr-content'>";
			$c .= "<div class='e10-wr-data'></div>";
			$c .= "<div class='e10-wr-params close'>";
				$c .= "<div class='tlbr e10-reportPanel-toggle'><i class='fa fa-bars'></i></div>";
				$c .= "<div class='params' id='e10-report-panel'></div>";
			$c .= "</div>";
		$c .= '</div>';

		$c .= '</div>';

		if ($autoSelect)
			$c .= "<script>e10selectReport ($('#e10reportWidget').find('ul.e10-selectReport>li.auto'), 0);</script>";

		return $c;
	}
}

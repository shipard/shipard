<?php

namespace lib\cfg;
use e10\utils, \lib\cfg\SubMenuCreator;


/**
 * Class SettingsPanelCreator
 * @package lib\cfg
 */
class ReportsSubMenuCreator extends SubMenuCreator
{
	public function run ()
	{
		$reportsGroups = $this->app()->cfgItem ('reportsGroups');
		foreach ($reportsGroups as $groupId => $reportGroup)
		{
			$menuItemId = 'reports-'.$groupId;
			$subMenuItem = [
				't1' => $reportGroup['title'],
				'object' => "widget",
				"class" => "Shipard.Report.WidgetReports",
				"subclass" => $groupId,
				'icon' => $reportGroup['icon'],
				'order' => $reportGroup['order']
			];
			$this->subMenuContent['items'][$menuItemId] = $subMenuItem;
		}

		$order = 10000;
		$reports = $this->app()->cfgItem ('reports');
		foreach ($reports as $report)
		{
			$groupId = $report['group'];
			$menuItemId = 'reports-'.$groupId;

			if (isset($this->subMenuContent['items'][$menuItemId]))
				continue;

			$subMenuItem = [
				't1' => $groupId,
				'object' => "widget",
				"class" => "Shipard.Report.WidgetReports",
				"subclass" => $groupId,
				'icon' => 'icon-file-text',
				'order' => $order
			];

			$this->subMenuContent['items'][$menuItemId] = $subMenuItem;

			$order++;
		}
	}
}


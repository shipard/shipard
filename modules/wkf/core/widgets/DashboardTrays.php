<?php


namespace wkf\core\widgets;

use \Shipard\UI\Core\WidgetBoard, \wkf\base\TableSections;


/**
 * Class DashboardTrays
 * @package wkf\core\widgets
 */
class DashboardTrays extends WidgetBoard
{
	/** @var  \wkf\base\TableTrays */
	var $tableTrays;
	var $usersTrays;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '2';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if (substr ($this->activeTopTab, 0, 5) === 'tray-')
		{
			$parts = explode ('-', $this->activeTopTab);

			$this->addContentViewer('e10.base.attachments', 'lib.core.attachments.viewers.PanesCore', ['tray' => $parts[1], 'viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'search')
		{
			$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.DashboardIssuesSearch', ['viewerMode' => $viewerMode]);
		}
	}

	public function init ()
	{
		$this->tableTrays = $this->app->table ('wkf.base.trays');

		$this->createTabs();
		parent::init();
	}

	function addTraysTabs (&$tabs)
	{
		$this->usersTrays = $this->tableTrays->usersTrays();

		/*
		$marks = new \lib\docs\Marks($this->app());
		$marks->setMark(100);
		$marks->loadMarks('wkf.base.sections', array_keys($this->usersSections['top']));
		*/
		foreach ($this->usersTrays as $trayNdx => $tray)
		{
			$icon = 'icon-file';

			if (isset($tray['icon']) && $tray['icon'] !== '')
				$icon = $tray['icon'];

			$tabs['tray-'.$trayNdx] = [
				'line' => [
					['text' => $tray['sn'], 'icon' => $icon, 'class' => '']
				],
				'action' => 'load-tray-'.$trayNdx
			];
		}
	}

	function createTabs ()
	{
		$tabs = [];

		$this->addTraysTabs($tabs);
		//$tabs['search'] = ['icon' => 'icon-search', 'text' => '', 'action' => 'load-search'];

		$this->toolbar = ['tabs' => $tabs];
	}

	protected function initRightTabs ()
	{
		$rt = [
			'viewer-mode-2' => ['text' =>'', 'icon' => 'icon-th', 'action' => 'viewer-mode-2'],
			'viewer-mode-0' => ['text' =>'', 'icon' => 'icon-th-large', 'action' => 'viewer-mode-0'],
			//'viewer-mode-1' => ['text' =>'', 'icon' => 'icon-th-list', 'action' => 'viewer-mode-1'],
			'viewer-mode-3' => ['text' =>'', 'icon' => 'icon-square', 'action' => 'viewer-mode-3'],
		];

		$this->toolbar['rightTabs'] = $rt;
	}

	public function title()
	{
		return FALSE;
	}
}

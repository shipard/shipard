<?php


namespace plans\core\libs;

use \Shipard\UI\Core\WidgetBoard, \wkf\base\TableSections;


/**
 * Class Dashboard
 */
class Dashboard extends WidgetBoard
{
	var $treeMode = 0;
	var $help = 'prirucka/11';

	/** @var  \plans\core\TablePlans */
	var $tablePlans;
	var $usersPlans;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		if (!$this->usersPlans || !count($this->usersPlans))
		{
			return;
		}

		$viewerMode = '1';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if ($this->treeMode)
		{
			$this->addContentViewer('wkf.core.issues',
				'wkf.core.viewers.DashboardIssuesSectionsTree', ['widgetId' => $this->widgetId, 'viewerMode' => $viewerMode, 'help' => $this->help]);
			return;
		}

    //$this->addContent(['type' => 'line', 'line' => ['text' => 'nazdar']]);

    $parts = explode ('-', $this->activeTopTab);

		if ($viewerMode === 'gantt')
			$this->addContentViewer('plans.core.items', /*'plans.core.ViewItems'*/'plans.core.libs.ViewItemsGantt', ['plan' => $parts[1], 'viewerMode' => $viewerMode]);
		else
			$this->addContentViewer('plans.core.items', /*'plans.core.ViewItems'*/'plans.core.libs.ViewItemsGrid', ['plan' => $parts[1], 'viewerMode' => $viewerMode]);

	}

	public function init ()
	{
		$this->tablePlans = $this->app->table ('plans.core.plans');
		$this->treeMode = 0;//intval($this->app->cfgItem ('options.wkfn.dashboardSectionsSelect', 1));

		if (!$this->treeMode)
			$this->createTabs();
		else
			$this->toolbar = ['tabs' => []];

		parent::init();
	}

	function addPlansTabs (&$tabs)
	{
		$this->usersPlans = $this->tablePlans->usersPlans();

		if (!$this->usersPlans || !count($this->usersPlans))
		{
			return;
		}

		/*
    $marks = new \lib\docs\Marks($this->app());
		$marks->setMark(100);
		$marks->loadMarks('wkf.base.sections', array_keys($this->usersSections['top']));
    */

		foreach ($this->usersPlans as $planNdx => $p)
		{

			$icon = 'icon-file';
			if (isset($p['icon']) && $p['icon'] !== '')
				$icon = $p['icon'];

			$tab = [];
			$tab[] = ['text' => $p['sn'], 'icon' => $icon, 'class' => ''];

      /*
      if ($markEnable)
			{
				$nv = isset($marks->marks[$sectionNdx]) ? $marks->marks[$sectionNdx] : 0;
				if (!isset($marks->markCfg['states'][$nv]))
					$nv = 0;
				$nt = $marks->markCfg['states'][$nv]['name'];
				$tab[] = ['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$sectionNdx}' style='display:none;'></span>"];
				$tab[] = ['text' => '', 'icon' => $marks->markCfg['states'][$nv]['icon'], 'title' => $nt, 'class' => 'pl1 e10-off'];
			}
			elseif ($showNtfBadge)
				$tab[] = ['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$sectionNdx}' style='display:none;'></span>"];
      */
			$tabs['plan-'.$p['ndx']] = ['line' => $tab, 'ntfBadgeId' => 'ntf-badge-plans-p'.$p['ndx'], 'action' => 'load-plan-' . $p['ndx']];
		}
	}

	function addBoardsTabs (&$tabs)
	{
    /*
		$allBoards = $this->app()->cfgItem('wkf.issues.boards', []);
		foreach ($allBoards as $boardNdx => $boardCfg)
		{
			if ($boardCfg['addToMainDashboard'] === 0)
				continue;

			if ($boardCfg['addToMainDashboard'] === 1)
				$tab = ['text' => '', 'icon' => $boardCfg['icon'], 'action' => 'load-board'];
			else
				$tab = ['text' => $boardCfg['sn'], 'icon' => $boardCfg['icon'], 'action' => 'load-board'];

			$tabs['board-'.$boardNdx] = $tab;
		}
    */
	}

	function createTabs ()
	{
		$tabs = [];

		$this->addPlansTabs($tabs);
		$this->addBoardsTabs($tabs);

		$this->toolbar = ['tabs' => $tabs];
	}

	protected function initRightTabs ()
	{
		$rt = [
			'viewer-mode-table' => ['text' => '', 'icon' => 'system/dashboardModeRows', 'action' => 'viewer-mode-table'],
			'viewer-mode-gantt' => ['text' => '', 'icon' => 'system/iconCalendar', 'action' => 'viewer-mode-gantt'],
		];

		$this->toolbar['rightTabs'] = $rt;
	}

	public function title()
	{
		return FALSE;
	}
}

<?php

namespace e10mnf\core\libs;
use \Shipard\UI\Core\WidgetBoard;




class DashboardWorkOrdersWithoutPlan extends WidgetBoard
{
	var $treeMode = 0;

	/** @var  \plans\core\TablePlans */
	var $tablePlans;
	var $usersPlans;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '1';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];


    $this->addContentViewer('e10mnf.core.workOrders', 'e10mnf.core.libs.ViewWorkOrdersWithoutPlan', ['plan' => 123, 'viewerMode' => 'test']);
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

	function createTabs ()
	{
    return;
		$tabs = [];

		$this->addPlansTabs($tabs);

    /*
		$tabs['marked'] = ['icon' => 'system/iconStar', 'text' => '', 'title' => 'Označené', 'action' => 'load-marked'];
		$tabs['user'] = ['icon' => 'icon-user-circle-o', 'text' => '', 'title' => $this->app->user()->data('name'), 'action' => 'load-user'];
		$tabs['search'] = ['icon' => 'icon-search', 'text' => '', 'title' => 'Hledat', 'action' => 'load-search'];
    */
		$this->toolbar = ['tabs' => $tabs];
	}

	protected function initRightTabsXXX ()
	{
		$testDIV = intval($this->app()->cfgItem ('options.experimental.testWkfViewerInDashboard', 0));

		if ($testDIV)
		{
			$rt = [
				'viewer-mode-2' => ['text' => '', 'icon' => 'system/dashboardModeRows', 'action' => 'viewer-mode-1'],
				'viewer-mode-5' => ['text' =>'', 'icon' => 'system/dashboardModeViewer', 'action' => 'viewer-mode-5'],
			];
		}
		else
		{
			$rt = [
				'viewer-mode-1' => ['text' => '', 'icon' => 'system/dashboardModeRows', 'action' => 'viewer-mode-1'],
				'viewer-mode-2' => ['text' => '', 'icon' => 'system/dashboardModeTilesSmall', 'action' => 'viewer-mode-2'],
				//'viewer-mode-3' => ['text' => '', 'icon' => 'system/dashboardModeTilesBig', 'action' => 'viewer-mode-3'],
				'viewer-mode-0' => ['text' => '', 'icon' => 'system/dashboardModeTilesBig', 'action' => 'viewer-mode-0'],
			];
		}

		if (substr ($this->activeTopTab, 0, 8) === 'section-')
		{
			$parts = explode('-', $this->activeTopTab);
			$topSectionCfg = $this->usersSections['top'][$parts[1]];

			//if ($topSectionCfg['useStatuses'])
			//	$rt['viewer-mode-6'] = ['text' => '', 'icon' => 'icon-tasks', 'action' => 'viewer-mode-6'];
			//if ($topSectionCfg['useTargets'])
			//	$rt['viewer-mode-7'] = ['text' => '', 'icon' => 'icon-flag-checkered', 'action' => 'viewer-mode-7'];
		}

		$this->toolbar['rightTabs'] = $rt;
	}

	public function title()
	{
		return FALSE;
	}
}

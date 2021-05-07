<?php


namespace wkf\core\widgets;

use \Shipard\UI\Core\WidgetBoard, \wkf\base\TableSections;


/**
 * Class Dashboard
 */
class Dashboard extends WidgetBoard
{
	var $treeMode = 0;
	var $help = 'prirucka/4599';

	/** @var  \wkf\base\TableSections */
	var $tableSections;
	var $usersSections;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if ($this->treeMode)
		{
			$this->addContentViewer('wkf.core.issues',
				'wkf.core.viewers.DashboardIssuesSectionsTree', ['widgetId' => $this->widgetId, 'viewerMode' => $viewerMode, 'help' => $this->help]);
			return;
		}

		if (substr ($this->activeTopTab, 0, 8) === 'section-')
		{
			$parts = explode ('-', $this->activeTopTab);

			$section = $this->usersSections['top'][$parts[1]];
			if ($section['sst'] == 10)
				$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.DashboardBBoard', ['section' => $parts[1], 'viewerMode' => $viewerMode]);
			else
				$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.DashboardIssuesSection', ['section' => $parts[1], 'viewerMode' => $viewerMode, 'help' => $this->help]);
		}
		elseif (substr ($this->activeTopTab, 0, 6) === 'board-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.IssuesBoardColumns', ['board' => $parts[1], 'viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'marked')
		{
			$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.DashboardIssuesMarked', ['viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'search')
		{
			$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.DashboardIssuesSearch', ['viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'user')
		{
			$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.DashboardIssuesUser', ['viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'documents')
		{
			$this->addContentViewer('e10pro.wkf.documents', 'lib.wkf.ViewerDocumentsAll', ['viewerMode' => $viewerMode]);
		}
	}

	public function init ()
	{
		$this->tableSections = $this->app->table ('wkf.base.sections');
		$this->treeMode = intval($this->app->cfgItem ('options.wkfn.dashboardSectionsSelect', 1));

		if (!$this->treeMode)
			$this->createTabs();
		else
			$this->toolbar = ['tabs' => []];

		parent::init();
	}

	function addSectionsTabs (&$tabs)
	{
		$this->usersSections = $this->tableSections->usersSections();

		$marks = new \lib\docs\Marks($this->app());
		$marks->setMark(100);
		$marks->loadMarks('wkf.base.sections', array_keys($this->usersSections['top']));

		foreach ($this->usersSections['top'] as $sectionNdx => $s)
		{
			if (isset($s['subSections']) && count($s['subSections']) && !count($s['ess']))
				continue;

			$icon = 'icon-file';
			if (isset($s['icon']) && $s['icon'] !== '')
				$icon = $s['icon'];

			$markEnable = 1;
			$showNtfBadge = 1;
			if ($s['sst'] == 10) {
				$markEnable = 0;
				$showNtfBadge = 0;
			}
			if (isset($s['subSections']) && count($s['subSections']))
				$markEnable = 0;

			$tab = [];
			$tab[] = ['text' => $s['sn'], 'icon' => $icon, 'class' => ''];
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
			$tabs['section-'.$s['ndx']] = ['line' => $tab, 'ntfBadgeId' => 'ntf-badge-wkf-s'.$s['ndx'], 'action' => 'load-section-' . $s['ndx']];
		}
	}

	function addBoardsTabs (&$tabs)
	{
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
	}

	function createTabs ()
	{
		$tabs = [];

		$this->addSectionsTabs($tabs);
		$this->addBoardsTabs($tabs);
		$tabs['marked'] = ['icon' => 'icon-star', 'text' => '', 'title' => 'Označené', 'action' => 'load-marked'];
		$tabs['user'] = ['icon' => 'icon-user-circle-o', 'text' => '', 'title' => $this->app->user()->data('name'), 'action' => 'load-user'];
		$tabs['search'] = ['icon' => 'icon-search', 'text' => '', 'title' => 'Hledat', 'action' => 'load-search'];

		$this->toolbar = ['tabs' => $tabs];
	}

	protected function initRightTabs ()
	{
		$testDIV = intval($this->app()->cfgItem ('options.experimental.testWkfViewerInDashboard', 0));

		if ($testDIV)
		{
			$rt = [
				'viewer-mode-2' => ['text' => '', 'icon' => 'icon-th', 'action' => 'viewer-mode-2'],
				'viewer-mode-5' => ['text' =>'', 'icon' => 'icon-window-maximize', 'action' => 'viewer-mode-5'],
			];
			//$rt['viewer-mode-5'] = ['text' =>'', 'icon' => 'icon-window-maximize', 'action' => 'viewer-mode-5'];
		}
		else
		{
			$rt = [
				'viewer-mode-2' => ['text' => '', 'icon' => 'icon-th', 'action' => 'viewer-mode-2'],
				'viewer-mode-1' => ['text' => '', 'icon' => 'icon-th-list', 'action' => 'viewer-mode-1'],
				'viewer-mode-3' => ['text' => '', 'icon' => 'icon-square', 'action' => 'viewer-mode-3'],
				'viewer-mode-0' => ['text' => '', 'icon' => 'icon-th-large', 'action' => 'viewer-mode-0'],
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

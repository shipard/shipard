<?php


namespace wkf\docs\widgets;

use \Shipard\UI\Core\WidgetBoard, \wkf\docs\TableFolders;


/**
 * Class Dashboard
 * @package wkf\docs\widgets
 */
class Dashboard extends WidgetBoard
{
	var $treeMode = 0;
	var $help = 'prirucka/176';

	/** @var  \wkf\docs\TableFolders */
	var $tableFolders;
	var $usersFolders;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		//$this->addContent(['type' => 'line', 'line' => ['text' => 'test']]);
		//return;

		if ($this->treeMode)
		{
			$this->addContentViewer('wkf.docs.documents',
				'wkf.docs.viewers.DashboardDocumentsTree', ['widgetId' => $this->widgetId, 'help' => $this->help]);
			return;
		}

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if (substr ($this->activeTopTab, 0, 7) === 'folder-')
		{
			$parts = explode ('-', $this->activeTopTab);

			$folder = $this->usersFolders['top'][$parts[1]];
			$this->addContentViewer('wkf.docs.documents', 'wkf.docs.viewers.DashboardDocumentsCore', ['folder' => $parts[1], 'viewerMode' => $viewerMode, 'help' => $this->help]);
		}
		elseif ($this->activeTopTab === 'search')
		{
			//$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.DashboardIssuesSearch', ['viewerMode' => $viewerMode]);
		}
	}

	public function init ()
	{
		$this->tableFolders = $this->app->table ('wkf.docs.folders');
		$this->treeMode = intval($this->app->cfgItem ('options.wkfn.dashboardDocsFoldersSelect', 1));

		if (!$this->treeMode)
			$this->createTabs();

		parent::init();
	}

	function addSectionsTabs (&$tabs)
	{
		$this->usersFolders = $this->tableFolders->usersFolders();

		//$marks = new \lib\docs\Marks($this->app());
		//$marks->setMark(100);
		//$marks->loadMarks('wkf.base.sections', array_keys($this->usersSections['top']));

		foreach ($this->usersFolders['top'] as $folderNdx => $f)
		{
			if (isset($f['subFolders']) && count($f['subFolders']) && !count($f['esf']))
				continue;

			$icon = 'icon-folder';
			if (isset($f['icon']) && $f['icon'] !== '')
				$icon = $f['icon'];


			$tab = [];
			$tab[] = ['text' => $f['sn'], 'icon' => $icon, 'class' => ''];
			$tabs['folder-'.$f['ndx']] = ['line' => $tab, 'action' => 'load-folder-' . $f['ndx']];
		}
	}

	function createTabs ()
	{
		$tabs = [];

		$this->addSectionsTabs($tabs);
		//$tabs['marked'] = ['icon' => 'icon-star', 'text' => '', 'title' => 'Označené', 'action' => 'load-marked'];
		//$tabs['user'] = ['icon' => 'icon-user-circle-o', 'text' => '', 'title' => $this->app->user()->data('name'), 'action' => 'load-user'];
		$tabs['search'] = ['icon' => 'system/actionInputSearch', 'text' => '', 'title' => 'Hledat', 'action' => 'load-search'];

		$this->toolbar = ['tabs' => $tabs];
	}

	protected function initRightTabs ()
	{
		if ($this->treeMode)
			return;
		$rt = [
			'viewer-mode-1' => ['text' => '', 'icon' => 'system/dashboardModeRows', 'action' => 'viewer-mode-1'],
			'viewer-mode-2' => ['text' => '', 'icon' => 'system/dashboardModeTilesSmall', 'action' => 'viewer-mode-2'],
			//'viewer-mode-3' => ['text' => '', 'icon' => 'system/dashboardModeTilesBig', 'action' => 'viewer-mode-3'],
			'viewer-mode-0' => ['text' => '', 'icon' => 'system/dashboardModeTilesBig', 'action' => 'viewer-mode-0'],
		];

		$this->toolbar['rightTabs'] = $rt;
	}

	public function title()
	{
		return FALSE;
	}
}

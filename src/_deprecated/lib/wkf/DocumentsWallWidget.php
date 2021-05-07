<?php


namespace lib\wkf;

require_once __APP_DIR__ . '/e10-modules/e10pro/wkf/wkf.php';


use \e10\widgetBoard;


/**
 * Class DocumentsWallWidget
 * @package lib\wkf
 */
class DocumentsWallWidget extends widgetBoard
{
	var $useWiki = FALSE;
	var $useDocuments = FALSE;
	var $useCompany = FALSE;

	/** @var  \e10\DbTable */
	var $table;
	/** @var  \e10\DbTable */
	var $tableProjects;
	var $usersProjects;
	/** @var  \e10\DbTable */
	var $tableWikies;

	public function composeCodeCalendar()
	{
		$w = new \e10pro\wkf\widgetDashboardCalendarBig($this->app);
		$w->init();
		$w->createContent();

		$this->addContent($w->content[0]);
		$this->addContent($w->content[1]);
	}

	public function composeCodeCompany()
	{
		if (!$this->useCompany)
			return;

		$o = new \lib\dashboards\CompanyOverview($this->app);
		$o->run();

		$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $o->code]);
	}

	public function composeCodeMap($mapNdx)
	{
		$w = new \lib\wkf\WidgetMap($this->app);
		$w->mapNdx = $mapNdx;
		$w->init();
		$w->createContent();

		$this->addContent($w->content[0]);
	}

	public function composeCodeWiki($wikiNdx)
	{
		$w = new \e10pro\kb\WidgetWiki($this->app);
		$w->wikiNdx = $wikiNdx;
		$w->init();
		$w->createContent();

		$this->addContent($w->content[0]);
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if ($this->activeTopTab === 'bboard')
		{
			$this->addContentViewer('e10pro.wkf.messages', 'lib.wkf.ViewerDashboardBBoard', ['viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'newsBoard')
		{
			$this->addContentViewer('e10pro.wkf.messages', 'lib.wkf.ViewerDashboardToSolve', ['viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'projects')
		{
			$this->addContentViewer('e10pro.wkf.messages', 'lib.wkf.ViewerDashboardIssues', ['viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'search')
		{
			$this->addContentViewer('e10pro.wkf.messages', 'lib.wkf.ViewerDashboardSearch', ['viewerMode' => $viewerMode]);
		}
		elseif (substr ($this->activeTopTab, 0, 9) === 'projects-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$this->addContentViewer('e10pro.wkf.messages', 'lib.wkf.ViewerDashboardIssues', ['projectGroup' => $parts[1], 'viewerMode' => $viewerMode]);
		}
		elseif ($this->activeTopTab === 'calendar')
		{
			$this->composeCodeCalendar();
		}
		elseif ($this->activeTopTab === 'documents')
		{
			$this->addContentViewer('e10pro.wkf.documents', 'lib.wkf.ViewerDocumentsAll', ['viewerMode' => $viewerMode]);
		}
		elseif (substr ($this->activeTopTab, 0, 5) === 'wiki-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$this->composeCodeWiki(intval($parts[1]));
		}
		elseif ($this->activeTopTab === 'company')
		{
			$this->composeCodeCompany();
		}
		elseif ($this->activeTopTab === 'notes')
		{
			$this->addContentViewer('e10pro.wkf.messages', 'lib.wkf.ViewerDashboardNotes', ['viewerMode' => $viewerMode]);
		}
		elseif (substr ($this->activeTopTab, 0, 4) === 'map-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$this->composeCodeMap(intval($parts[1]));
		}
	}

	public function init ()
	{
		$this->table = $this->app->table ('e10pro.wkf.messages');
		$this->tableProjects = $this->app->table ('e10pro.wkf.projects');
		$this->usersProjects = $this->tableProjects->usersProjects(FALSE, TRUE);
		$this->tableWikies = $this->app->table ('e10pro.kb.wikies');

		$this->useWiki = intval($this->app->cfgItem ('options.wikies.useWiki', 0));
		if ($this->useWiki !== 1)
			$this->useWiki = 0;
		$this->useDocuments = intval($this->app->cfgItem ('options.workflow.useDocuments', 0));
		if ($this->useDocuments !== 1)
			$this->useDocuments = 0;

		// -- company overview?
		if ($this->app->model()->module ('e10doc.core') !== FALSE)
		{
			if ($this->app->hasRole('all') || $this->app->hasRole('bsass'))
				$this->useCompany = TRUE;
		}

		$this->createTabs();

		parent::init();
	}

	function addProjectsTabs (&$tabs)
	{
		$projectsGroups = $this->app->cfgItem('e10pro.wkf.projectsGroups');

		if (!count($this->usersProjects['groups']))
		{
			if (count($this->usersProjects['projects']))
				$tabs['projects'] = ['icon' => 'icon-lightbulb-o', 'text' => 'Projekty', 'action' => 'load-projects'];
			return;
		}

		foreach ($projectsGroups as $pgNdx => $pg)
		{
			$icon = 'icon-lightbulb-o';
			if (isset($pg['icon']) && $pg['icon'] !== '')
				$icon = $pg['icon'];
			if (count($this->usersProjects['groups'][$pgNdx]))
				$tabs['projects-'.$pgNdx] = ['icon' => $icon, 'text' => $pg['sn'], 'action' => 'load-projects-'.$pgNdx];
		}

		if (count($this->usersProjects['groups'][0]))
		{
			$tabs['projects-0'] = ['icon' => 'icon-lightbulb-o', 'text' => 'Projekty', 'action' => 'load-projects-0'];
		}
	}

	function addWikiesTabs (&$tabs)
	{
		$usersWikies = $this->tableWikies->usersWikies (1);
		foreach ($usersWikies as $w)
		{
			$icon = 'icon-book';
			if (isset($w['icon']) && $w['icon'] !== '')
				$icon = $w['icon'];
			$tabs['wiki-'.$w['ndx']] = ['icon' => $icon, 'text' => $w['sn'], 'action' => 'load-wiki-'.$w['ndx']];
		}
	}

	function addMapsTabs (&$tabs)
	{
		$testMaps = $this->app->cfgItem ('options.experimental.testMaps', 0);
		if (!$testMaps)
			return;
		$maps = $this->app->cfgItem ('e10pro.wkf.maps');
		foreach ($maps as $m)
		{
			if ($m['dashboardMain'] != 1)
				continue;

			$icon = 'icon-map-o';
			if (isset($m['icon']) && $m['icon'] !== '')
				$icon = $m['icon'];
			$tabs['map-'.$m['ndx']] = ['icon' => $icon, 'text' => $m['sn'], 'action' => 'load-map-'.$m['ndx']];
		}
	}

	function createTabs ()
	{
		$tabs = [];

		$tabs['bboard'] = ['icon' => 'icon-thumb-tack', 'text' => 'Nástěnka', 'action' => 'load-bboard'];

		$tabs['newsBoard'] = ['icon' => 'icon-smile-o', 'text' => 'Moje úkoly', 'action' => 'load-news-board'];
		$this->addProjectsTabs($tabs);
		//$tabs['notes'] = ['icon' => 'icon-pencil-square', 'text' => 'Poznámky', 'action' => 'load-notes'];

		$tabs['calendar'] = ['icon' => 'icon-calendar-o', 'text' => 'Kalendář', 'action' => 'load-calendar'];
		$tabs['search'] = ['icon' => 'icon-search', 'text' => '', 'action' => 'load-search'];

		if ($this->useDocuments)
			$tabs['documents'] = ['icon' => 'icon-archive', 'text' => 'Dokumenty', 'action' => 'load-documents'];

		if ($this->useWiki)
			$this->addWikiesTabs($tabs);
			//$tabs['wiki'] = ['icon' => 'icon-book', 'text' => 'Wiki', 'action' => 'load-wiki'];

		if ($this->useCompany)
			$tabs['company'] = ['icon' => 'icon-building', 'text' => 'Firma', 'action' => 'load-company'];

		$this->addMapsTabs($tabs);

		$this->toolbar = ['tabs' => $tabs];

		$rt = [
				'viewer-mode-2' => ['text' =>'', 'icon' => 'icon-th', 'action' => 'viewer-mode-2'],
				'viewer-mode-1' => ['text' =>'', 'icon' => 'icon-th-list', 'action' => 'viewer-mode-1'],
				'viewer-mode-3' => ['text' =>'', 'icon' => 'icon-square', 'action' => 'viewer-mode-3'],
				'viewer-mode-0' => ['text' =>'', 'icon' => 'icon-th-large', 'action' => 'viewer-mode-0'],
			];

		$this->toolbar['rightTabs'] = $rt;
	}

	public function title()
	{
		return FALSE;
	}
}

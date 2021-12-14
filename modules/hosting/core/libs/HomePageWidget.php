<?php

namespace hosting\core\libs;
use \Shipard\UI\Core\WidgetBoard;
use \Shipard\Utils\Utils;


/**
 * Class HomePageWidget
 * @package hosting\core\libs
 */
class HomePageWidget extends WidgetBoard
{
	var $dataSources = [];
	var $dsBBoard = [];
	var $activeTabId = '';

	var $today;
	var $mobileMode;

	public function init ()
	{
		$this->activeTabId = $this->app()->testGetParam ('e10-widget-dashboard-tab-id');

		if ($this->activeTabId === '')
			$this->activeTabId = 'tab-home';

		if ($this->activeTabId === 'tab-home')
			$this->createDashboard_BBoard_Toolbar();

		parent::init();
	}

	function loadDataToolbar()
	{
		$maxToolbarCnt = 12;

		$qf[] = 'SELECT dsUsers.dataSource,';
		array_push($qf, ' dataSources.shortName AS dsShortName, dataSources.name AS dsFullName, dataSources.urlApp AS dsUrl, dataSources.gid AS dsGid,');
		array_push($qf, ' dataSources.imageUrl AS dsImageUrl, dataSources.dsEmoji AS dsEmoji, dataSources.dsIcon AS dsIcon');
		array_push($qf, ' FROM [hosting_core_dsUsersOptions] AS dsOptions');
		array_push($qf, ' INNER JOIN [hosting_core_dsUsers] AS dsUsers ON dsOptions.uds = dsUsers.ndx');
		array_push($qf, ' INNER JOIN [hosting_core_dataSources] AS dataSources ON dsUsers.dataSource = dataSources.ndx');
		array_push($qf, ' WHERE 1');
		array_push($qf, ' AND dsUsers.[user] = %i', $this->app()->userNdx());
		array_push($qf, ' AND dsUsers.[docStateMain] = 2');
		array_push($qf, ' AND dsOptions.[addToToolbar] <= %i', 1);
		
		array_push($qf, ' ORDER BY dsOptions.[addToToolbar] DESC, dsOptions.[toolbarOrder], dataSources.[name]');		
		array_push($qf, ' LIMIT 0, %i', $maxToolbarCnt);
		$rows = $this->db()->query($qf);

		foreach ($rows as $r)
		{
			$item = [
				'title' => ($r['dsShortName'] === '') ? $r['dsFullName'] : $r['dsShortName'], 'dsUrl' => $r['dsUrl'],
				'dsImageUrl' => $r['dsImageUrl'], 'dsEmoji' => $r['dsEmoji'], 'dsIcon' => $r['dsIcon'],
				'gid' => $r['dsGid'],
			];
			$this->dataSources[$r['dataSource']] = $item;
		}
	}

	function loadDataBBoard()
	{
		$maxDashboardCnt = 19;

		$qf[] = 'SELECT dsUsers.dataSource,';
		array_push($qf, ' dataSources.shortName AS dsShortName, dataSources.name AS dsFullName, dataSources.urlApp AS dsUrl, dataSources.gid AS dsGid,');
		array_push($qf, ' dataSources.imageUrl AS dsImageUrl, dataSources.dsEmoji AS dsEmoji, dataSources.dsIcon AS dsIcon,');
		array_push($qf, ' dsOptions.addToDashboard');
		array_push($qf, ' FROM [hosting_core_dsUsersOptions] AS dsOptions');
		array_push($qf, ' INNER JOIN [hosting_core_dsUsers] AS dsUsers ON dsOptions.uds = dsUsers.ndx');
		array_push($qf, ' INNER JOIN [hosting_core_dataSources] AS dataSources ON dsUsers.dataSource = dataSources.ndx');
		array_push($qf, ' WHERE 1');
		array_push($qf, ' AND dsUsers.[user] = %i', $this->app()->userNdx());
		array_push($qf, ' AND dsUsers.[docStateMain] = 2');
		array_push($qf, ' AND dsOptions.[addToDashboard] != %i', 9);		
		array_push($qf, ' ORDER BY dsOptions.[addToDashboard] DESC, dsOptions.[dashboardOrder], dataSources.[name]');		
		array_push($qf, ' LIMIT 0, %i', $maxDashboardCnt);
		$rows = $this->db()->query($qf);

		$cnt = 0;
		foreach ($rows as $r)
		{
			$item = [
				'title' => ($r['dsShortName'] === '') ? $r['dsFullName'] : $r['dsShortName'], 'dsUrl' => $r['dsUrl'],
				'dsImageUrl' => $r['dsImageUrl'], 'dsEmoji' => $r['dsEmoji'], 'dsIcon' => $r['dsIcon'],
				'gid' => $r['dsGid'],
			];

			$dashboardPriorityId = $r['addToDashboard'];
			if ($dashboardPriorityId === 0)
			{
				if ($cnt > 6)	
					$dashboardPriorityId = 1;
				elseif ($cnt > 2)	
					$dashboardPriorityId = 2;
				else $dashboardPriorityId = 3;	
			}

			$this->dsBBoard[$dashboardPriorityId][$r['dataSource']] = $item;
			$cnt++;
		}
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;
		$this->loadDataToolbar();

		$this->createContent_Dashboard();
	}

	public function createContent_Dashboard()
	{
		$this->createDashboard_LeftBar();
		$this->createDashboard_RightBar();
	}

	function createDashboard_LeftBar()
	{
		$active = ($this->activeTabId === 'tab-home') ? ' active' : '';

		$c = '';

		$c .= "<style>div.e10-wdb {border: none;} div.e10-viewer-fw-toolbar div.e10-sv-fw-toolbar{border: none;}</style>";

		$c .= "<div class='e10-wf-tabs-vertical' style='width: 4rem; padding: 0; background-color: #00508aa0; overflow-y: auto;'>";
		$c .= "<input type='hidden' name='e10-widget-dashboard-tab-id' id='e10-widget-dashboard-tab-id' value='{$this->activeTabId}'>";

		$c .= "<ul class='e10-wf-tabs' data-value-id='e10-widget-dashboard-tab-id'>";

		$c .= "<li class='tab bb1 e10-widget-trigger$active' data-tabid='".'tab-home'."' id='dstab-home'  style='padding: 0;'>";
		$c .= "<div style='font-size: 3rem; text-align:center; width: 4rem; height: 4rem;'>";
		$c .= $this->app()->ui()->icon('system/iconHome');
		$c .= "</div>";
		$c .= "</li>";

		foreach ($this->dataSources as $dsNdx => $ds)
		{
			$active = ($dsNdx == $this->activeTabId) ? ' active' : '';
			$c .= "<li class='tab bb1 e10-widget-trigger$active' data-tabid='".$dsNdx."' id='e10-lanadmin-dstab-{$dsNdx}' style='padding: 0;'>";

			if ($ds['dsImageUrl'] !== '')
			{
				$c .= "<div title=\"".Utils::es($ds['title'])."\" style='width: 4rem; height: 4rem; background-repeat: no-repeat; background-image:url({$ds['dsImageUrl']}); background-size: 80%; background-position: center center;'>";
				$c .= "</div>";
			}
			elseif ($ds['dsEmoji'] !== '')
			{
				$c .= "<div title=\"".Utils::es($ds['title'])."\" style='font-size: 3rem; max-width: 4rem; width: 4rem; height: 4rem; padding: 0.4rem; overflow: hidden; text-align: center; display: inline;'>";
				$c .= Utils::es($ds['dsEmoji']);
				$c .= "</div>";	
			}
			elseif ($ds['dsIcon'] !== '')
			{
				$c .= "<div title=\"".Utils::es($ds['title'])."\" style='font-size: 2.6rem; max-width: 4rem; width: 4rem; height: 4rem; padding: 0.4rem; overflow: hidden; text-align: center;'>";
				$c .= $this->app->ui()->icon($ds['dsIcon']);
				$c .= "</div>";	
			}
			else
			{
				$c .= "<div title=\"".Utils::es($ds['title'])."\" style='max-width: 4rem; width: 4rem; height: 4rem; padding: 0.4rem; overflow: hidden; text-overflow: \"…\";'>";
				$c .= Utils::es($ds['title']);
				$c .= "</div>";	
			}

			$c .= "</li>";
		}

		$c .= "<ul>";
		$c .= "</div>";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	function createDashboard_RightBar()
	{
		if ($this->activeTabId === 'tab-home')
		{
			$this->createDashboard_BBoard();
			return;
		}

		$dsNdx = intval($this->activeTabId);
		if (!isset($this->dataSources[$dsNdx]))
		{
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => 'Něco se pokazilo']);
			return;
		}

		$iframeUrl = $this->dataSources[$dsNdx]['dsUrl'].'app/dashboard';
		$c = '';
		$c .= "<div style='width: calc(100% - 4rem); height: 100%; float: left;'>";
		$c .= "<iframe data-sandbox='allow-scripts' frameborder='0' height='100%' width='100%' style='width:100%;height:calc(100%);' src='{$iframeUrl}'></iframe>";
		$c .= '</div>';		
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	protected function createDashboard_BBoard()
	{
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => '<div style="float: right; width: calc(100% - 4rem - 1px); height: 100%; position: absolute; left: 4rem;">']);

		$toolbarCode = parent::renderContentTitle();
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $toolbarCode]);

		// -- databases
		if ($this->activeTopTab === 'viewer-mode-dbs')
		{
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => '<div style="float: right; width: calc(100% - 1px); height: calc(100% - 3rem); position: absolute; left: 0;">']);
			$this->addContentViewer('hosting.core.dataSources', 'hosting.core.libs.ViewerDashboardUsersDataSources', ['widgetId' => $this->widgetId]);
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => '</div>']);

			return;
		}
		
		// -- overview
		if ($this->activeTopTab === 'viewer-mode-home')
		{
			$this->loadDataBBoard();
			foreach ($this->dsBBoard as $dashboardPriorityId => $dbDataSources)
			{
				$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);

				foreach ($dbDataSources as $dsId => $ds)
				{
					$this->createDashboard_BBoard_DSTile($ds, $dashboardPriorityId);
				}

				$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);
			}
		}	

		// -- footer
		$fc = '';
		$fc .= "<div class='' style='width: 100%; position: absolute; bottom: 1em; text-align: center; padding-top: 1rem;'>";
		$fc .= "powered by <a href='https://shipard.org/' target='_new'>shipard.org</a>";
		$fc .= " | <a href='https://shipard.org/prirucka' target='_new'>Příručka</a>";
		$fc .= " | <a href='https://forum.shipard.org/' target='_new'>Fórum</a>";
		$fc .= "<br>";
		$si = $this->app()->cfgItem ('serverInfo', 0);
		$fc .= "<small>";
		$fc .= utils::es('Verze '.__E10_VERSION__.'.'.$si['e10commit']);
		$fc .= ($this->app->mobileMode) ? '.m' : '.d';
		$fc .= ".<span class='visible-xs-inline'>xs</span><span class='visible-sm-inline'>sm</span><span class='visible-md-inline'>md</span><span class='visible-lg-inline'>lg</span>";
		$fc .= utils::es('.'.$si['channelId']);
		$fc .= '</small>';
		$fc .= '<br/>';
		$fc .= '</div>';

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $fc]);

		// -- end
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => '</div>']);
	}

	function createDashboard_BBoard_Toolbar ()
	{
		$userInfo = $this->app()->user()->data();
		$logoutUrl = $this->app()->urlRoot . '/' . $this->app()->appSkeleton['userManagement']['pathBase'] . '/' . $this->app()->appSkeleton['userManagement']['pathLogoutCheck'];

		$tabs = [];

		$tabs['viewer-mode-home'] = ['text' => ' Přehled', 'icon' => 'system/iconStart', 'action' => 'viewer-mode-home'];
		$tabs['viewer-mode-dbs'] = ['text' => ' Databáze', 'icon' => 'system/iconDatabase', 'action' => 'viewer-mode-dbs'];
	
		$this->toolbar = ['tabs' => $tabs];

		$btns = [];
		$btns[] = ['text' => $userInfo['name'], 'prefix' => ' ', 'class' => '', 'icon' => 'system/iconUser',];
		$btns[] = [
			'type' => 'action', 'action' => 'open-link', 
			'icon' => 'system/actionLogout',
			'data-url-download' => $logoutUrl, 'data-popup-id' => 'THIS-TAB',
			'title' => 'Odhlásit', 'text' => '', 'element' => 'li', 'btnClass' => 'tab'
		];

		$this->toolbar['buttons'] = $btns;
		$this->toolbar['logos'] = [
			'https://system.shipard.app/att/2017/09/26/e10pro.wkf.documents/shipard-logo-header-web-t9n9ug.svg'
		];
	}

	protected function createDashboard_BBoard_DSTile($ds, $dashboardPriorityId)
	{
		$width = 4;
		if ($dashboardPriorityId === 2)
			$width = 3;
		elseif ($dashboardPriorityId === 1)
			$width = 2;

		$dsTile = ['title' => [], 'body' => [], 'class' => 'df2-action-trigger'];
		$dsTile['data'] =
		[
			'action' => 'open-link', 'popup-id' => 'NEW-TAB',
			'url-download' => $ds['dsUrl'],
		];

		$title = [];
		
		if ($ds['dsImageUrl'] !== '')
		{
			$css = '';
			$css .= " background-image:url(\"{$ds['dsImageUrl']}\"); background-size: auto 90%; background-position: left center; background-repeat: no-repeat; width: 3rem; height: 3rem; display: block; float: left; margin: .2rem;";
			$title[] = ['text' => '', 'class' => '', 'css' => $css];
		}
		elseif ($ds['dsEmoji'] !== '')
		{
			$css = '';
			$css .= " width: 3rem; height: 3rem; display: block; float: left; font-size: 260%; margin: .2rem; text-align: center;";
			$title[] = ['text' => $ds['dsEmoji'], 'class' => '', 'css' => $css];
		}
		else
		{
			$icon = ($ds['dsIcon'] !== '') ? $ds['dsIcon'] : 'system/iconDatabase';
			$css = '';
			$css .= " width: 3rem; height: 3rem; display: block; float: left; font-size: 210%; margin: .2rem; text-align: center;";
			$title[] = ['text' => '', 'class' => '', 'icon' => $icon, 'css' => $css];
		}

		$dsTitleName = ['class' => 'h1 padd5', 'text' => $ds['title']];
		$title[] = $dsTitleName;

		$ntfBadge = "<sup class='e10-ntf-badge' id='ntf-badge-unread-ds-".utils::es($ds['gid'])."' style='display: none;'></sup>";
		$title[] = ['code' => $ntfBadge];

		$ntfBadge = "<sup class='e10-ntf-badge e10-ntf-badge-todo' id='ntf-badge-todo-ds-".utils::es($ds['gid'])."' style='display: none;'></sup>";
		$title[] = ['code' => $ntfBadge];

		$title[] = ['text' => '', 'class' => 'clear block'];

		$dsTile['title'][] = ['value' => $title, 'class' => 'e10-bg-t9 block'];
	
		$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => $width]);
			$this->addContent(['type' => 'tiles', 'tiles' => [$dsTile], 'class' => 'panes', 'pane' => 'e10-pane e10-pane-core']);
		$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
	}

	function renderContentTitle ()
	{
		return '';
	}	

	public function title() {return FALSE;}
}

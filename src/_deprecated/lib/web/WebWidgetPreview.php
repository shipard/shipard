<?php


namespace lib\web;

use \Shipard\UI\Core\WidgetBoard;


/**
 * Class WebWidgetPreview
 * @package lib\web
 */
class WebWidgetPreview extends WidgetBoard
{
	var $useWiki = FALSE;
	var $serverNdx = 0;
	var $adminMode = 0;

	public function composeCodeWebServer($serverNdx, $viewerMode)
	{
		$iframeActiveUrl = $this->app->testPostParam('iframeActiveUrl', '');

		$w = new \lib\web\WebWidgetPreviewOne($this->app);
		$w->serverNdx = $serverNdx;
		$w->deviceKind = $viewerMode;
		if (substr($this->widgetAction, 0, 11) === 'viewer-mode' && $iframeActiveUrl !== '')
			$w->forceUrl = $iframeActiveUrl;
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

		if (substr ($this->activeTopTab, 0, 7) === 'server-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$serverNdx = intval($parts[1]);
			$this->composeCodeWebServer($serverNdx, $viewerMode);
		}

	}

	public function init ()
	{
		$tt = $this->app->testGetParam('e10-widget-topTab');
		if ($tt === '')
			$tt = $this->app->testPostParam('e10-widget-topTab', '');

		if (substr ($tt, 0, 7) === 'server-')
		{
			$parts = explode ('-', $tt);
			$this->serverNdx = intval($parts[1]);
		}

		if (!$this->serverNdx)
		{
			$webServers = $this->app->cfgItem('e10.web.servers.list');
			$this->serverNdx = intval(key($webServers));
		}

		$this->createTabs();

		parent::init();
	}

	function addServersTabs (&$tabs)
	{
		$userNdx = $this->app->userNdx();
		$userGroups = $this->app->userGroups();

		$webServers = $this->app->cfgItem('e10.web.servers.list');

		foreach ($webServers as $serverNdx => $server)
		{
			if (isset($server['excludeFromDashboard']))
				continue;

			$enabled = 0;
			$admin = 0;
			if (!isset($server['allowAllUsers'])) $enabled = 1;
			elseif ($server['allowAllUsers']) {$enabled = 1; $admin = 1;}
			elseif (isset($server['admins']) && in_array($userNdx, $server['admins'])) {$enabled = 1; $admin = 1;}
			elseif (isset($server['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $server['adminsGroups'])) !== 0) $enabled = 1;
			elseif (isset($server['pageEditors']) && in_array($userNdx, $server['pageEditors'])) $enabled = 1;
			elseif (isset($server['pageEditorGroups']) && count($userGroups) && count(array_intersect($userGroups, $server['pageEditorGroups'])) !== 0) $enabled = 1;

			if (!$enabled)
				continue;

			$icon = 'icon-globe';
			$tabs['server-'.$serverNdx] = ['icon' => $icon, 'text' => $server['sn'], 'action' => 'load-server-'.$serverNdx];

			if ($this->serverNdx === $serverNdx && $admin)
				$this->adminMode = 1;
		}
	}

	function createTabs ()
	{
		$serverInfo = $this->app->cfgItem ('e10.web.servers.list.'.$this->serverNdx);

		$tabs = [];
		$this->addServersTabs ($tabs);

		$btns = [];
		$btns[] = ['text' => '', 'prefix' => ' ', 'class' => 'e10-off', 'icon' => 'icon-angle-right',];
		$btns[] = [
			'type' => 'widget', 'action' => 'edit-iframe-doc',
			'title' => 'Opravit stránku', 'text' => '', 'element' => 'li', 'btnClass' => 'btn-primary tab'
		];
		$btns[] = [
			'type' => 'widget', 'action' => 'new-iframe-doc',
			'title' => 'Přidat stránku', 'text' => '', 'element' => 'li', 'btnClass' => 'btn-success tab'
		];
		$btns[] = [
			'type' => 'widget', 'action' => 'open-iframe-tab', 'icon' => 'icon-external-link',
			'title' => 'Otevřít v nové záložce', 'text' => '', 'element' => 'li', 'btnClass' => 'tab'
		];

		if ($this->adminMode)
		{
			if (isset($serverInfo['lookNdx']) && $serverInfo['lookNdx'] && $serverInfo['lookNdx'] < 100000)
			{
				$btns[] = [
					'docAction' => 'edit', 'table' => 'e10.base.templatesLooks', 'pk' => $serverInfo['lookNdx'],
					'text' => '', 'title' => 'Vlastní nastavení vzhledu', 'icon' => 'icon-paint-brush', 'actionClass' => 'btn-info tab', 'type' => 'li',
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
				];
			}

			$btns[] = [
				'docAction' => 'edit', 'table' => 'e10.web.servers', 'pk' => $this->serverNdx,
				'text' => '', 'title' => 'Nastavení webu', 'icon' => 'icon-wrench','actionClass' => 'btn-warning tab', 'type' => 'li',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
			];
		}

		$this->toolbar = ['buttons' => $btns, 'tabs' => $tabs];

		$rt = [
			'viewer-mode-desktop' => ['text' =>'', 'icon' => 'icon-desktop', 'action' => 'viewer-mode-desktop'],
			'viewer-mode-mobile' => ['text' =>'', 'icon' => 'deviceTypes/phone', 'action' => 'viewer-mode-mobile'],
			'viewer-mode-tablet' => ['text' =>'', 'icon' => 'icon-tablet', 'action' => 'viewer-mode-tablet'],
			'viewer-mode-laptop' => ['text' =>'', 'icon' => 'icon-tablet fa-rotate-270', 'action' => 'viewer-mode-laptop'],
		];

		$this->toolbar['rightTabs'] = $rt;

		/*
		$logo = $this->app->cfgItem ('appSkeleton.logo', '');
		if ($logo !== '')
			$this->toolbar['logo'] = $logo;
		*/
	}

	public function title()
	{
		return FALSE;
	}
}

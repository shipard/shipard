<?php

namespace e10pro\hosting\client\libs;
use \e10\widgetBoard, \translation\dicts\e10\base\system\DictSystem;


/**
 * Class WidgetDataSources2
 * @package e10pro\hosting\client\libs
 */
class WidgetDataSources2 extends widgetBoard
{
	public function init ()
	{
		$tabs = [];

		$this->toolbar = ['tabs' => $tabs];
		$this->toolbar['logo'] = '/e10-modules/e10templates/web/shipard1/files/shipard/logo-header-portal.svg';

		parent::init();
		$this->widgetMainClass .= ' e10-bg-app-header';
	}

	protected function initRightTabs ()
	{
		$refreshCode = "<li class='e10-widget-trigger active' data-action='viewer-mode-0' data-tabid='viewer-mode-2'><i class='fa fa-refresh'></i></li>";
		$this->toolbar['buttons'][] = ['code' => $refreshCode, 'type' => 'li'];

		$this->toolbar['buttons'][] = ['text' => $this->app->user()->data ('name'), 'icon' => 'system/iconUser'];

		$logoutUrl = $this->app()->urlRoot . '/' . $this->app()->appSkeleton['userManagement']['pathBase'] . '/' . $this->app()->appSkeleton['userManagement']['pathLogoutCheck'];
		$logoutCode = "<li style='padding-right:0;'><a href='$logoutUrl' title='".DictSystem::es(DictSystem::diBtn_Logout)."' style='margin-top: auto;'><i class='fa fa-power-off' style='color: white!important;'></i></a></li>";
		$logoutBtn = ['code' => $logoutCode, 'type' => 'li'];

		$this->toolbar['buttons'][] = $logoutBtn;
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$this->addContentViewer('e10pro.hosting.server.datasources', 'e10pro.hosting.client.libs.DataSourcesDashboardViewer2', ['widgetId' => $this->widgetId]);
	}

	public function title()
	{
		return FALSE;
	}
}


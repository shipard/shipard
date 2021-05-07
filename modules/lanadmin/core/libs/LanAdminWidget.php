<?php

namespace lanadmin\core\libs;

use \e10\widgetBoard, \e10\utils, \e10\Utility, \e10\uiutils;


/**
 * Class LanAdminWidget
 * @package lanadmin\core
 */
class LanAdminWidget extends widgetBoard
{
	var $dataSources = [];

	var $dashboardDSNdx = 0;
	var $dashboardDSViewId = '';

	var $today;
	var $mobileMode;

	public function init ()
	{
		parent::init();
	}

	function loadData()
	{
		$q [] = 'SELECT [ds].* FROM [lanadmin_core_dataSources] AS [ds]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [ds].docState IN %in', [4000, 8000]);
		array_push ($q, ' ORDER BY [ds].[order], [ds].[shortName]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['title' => $r['shortName'], 'dsUrl' => $r['dsUrl']];
			$this->dataSources[$r['ndx']] = $item;
		}

	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;
		$this->loadData();

		$this->createContent_Dashboard();
	}

	public function createContent_Dashboard()
	{
		$c = '';

		$c .= $this->createDashboard_LeftBar();
		$c .= $this->createDashboard_RightBar();

		$c .= "<script type='text/javascript'>";
		$c .= "var lanAdminDataSources = ".json_encode($this->dataSources).";\n";
		$c .= '</script>';

		$c .= "\n\n<script src='".$this->app()->dsRoot."/e10-modules/lanadmin/core/js/lanAdmin.js?v3'></script>";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	function createDashboard_LeftBar()
	{
		$activeDSId = intval($this->app()->testGetParam ('e10-widget-dashboard-ds-ndx'));

		if (!isset($this->dataSources[$activeDSId]))
			$activeDSId = 0;

		$c = '';

		$c .= "<div class='e10-wf-tabs-vertical' style='width: 19em; background-color: #00508a;'>";
		$c .= "<input type='hidden' name='e10-widget-dashboard-ds-ndx' id='e10-widget-dashboard-ds-ndx' value='$activeDSId'>";

		$c .= "<ul class='e10-wf-tabs' data-value-id='e10-widget-dashboard-ds-ndx'>";
		foreach ($this->dataSources as $dsNdx => $ds)
		{
			if (!$activeDSId)
				$activeDSId = $dsNdx;

			$active = ($dsNdx === $activeDSId) ? ' active' : '';
			$c .= "<li class='tab bb1 e10-widget-trigger$active' data-tabid='".$dsNdx."' id='e10-lanadmin-dstab-{$dsNdx}'>";

			$c .= "<div class='h2 pt1'>";
			$c .= utils::es($ds['title']);
			$c .= "</div>";
			$c .= "<div class='badges pb1'>";
			$c .= "<span class='status'><small><i class='fa fa-circle'></i></small></span>&nbsp; ";
			$c .= "<span class='badges'></span>";
			$c .= "</div>";

			$c .= "</li>";
		}
		$c .= "<ul>";

		$c .= "</div>";

		$this->dashboardDSNdx = $activeDSId;

		return $c;
	}

	function createDashboard_RightBar()
	{
		$activeViewId = $this->app()->testGetParam ('e10-widget-dashboard-ds-view-id');

		$topBar = [];
		$item = [
			'title' => ['text' => 'TEST'],
			'type' => 'iframe',
			'url' => $this->dataSources[$this->dashboardDSNdx]['dsUrl'].'app/!/widget/dashboard/maclan/?disableAppMenu=1&disableLeftMenu=1&disableAppRightMenu=1&mainWidgetMode=1',
		];
		$topBar['realtime-full-view'] = $item;

		if (!isset($topBar[$activeViewId]))
			$activeViewId = '';

		$c = '';
		$c .= "<div style='width: calc(100% - 19em); height: 100%; float: left;'>";

		$c .= "<div class='e10-wf-tabs-horizontal' style='padding-top: .5ex; padding-left: 1ex; display: none;'>";
		$c .= "<input type='hidden' name='e10-widget-dashboard-ds-view-id' id='e10-widget-dashboard-ds-view-id' value='$activeViewId'>";

		$c .= "<ul class='e10-wf-tabs' data-value-id='e10-widget-dashboard-ds-view-id'>";

		foreach ($topBar as $viewId => $topBarItem)
		{
			if ($activeViewId === '')
				$activeViewId = $viewId;

			$active = ($viewId === $activeViewId) ? ' active' : '';

			$c .= "<li class='tab e10-widget-trigger$active' data-tabid='$viewId'>";
			$c .= $this->app()->ui()->composeTextLine($topBarItem['title']);
			$c .= "</li>";
		}

		$c .= '</ul>';

		$c .= '</div>';

		$this->dashboardDSViewId = $activeViewId;

		$activeTopBarItem = $topBar[$activeViewId];
		if ($activeTopBarItem['type'] === 'nothing')
		{
			$c .= $this->app()->ui()->composeTextLine(['text' => 'Monitoring tohoto zařízení není nastaven...', 'class' => 'padd5 e10-error']);
		}
		elseif ($activeTopBarItem['type'] === 'iframe')
		{
			$c .= "<iframe data-sandbox='allow-scripts' frameborder='0' height='100%' width='100%' style='width:100%;height:calc(100%);' src='{$activeTopBarItem['url']}'></iframe>";
		}

		return $c;
	}


	public function title() {return FALSE;}
}

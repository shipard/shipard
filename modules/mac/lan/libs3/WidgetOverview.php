<?php

namespace mac\lan\libs3;

use \Shipard\UI\Core\WidgetBoard, \e10\utils;


/**
 * Class WidgetOverview
 */
class WidgetOverview extends WidgetBoard
{
	var $lanNdx = 0;

	public function init ()
	{
		$this->createTabs();

		parent::init();
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if (substr ($this->activeTopTab, 0, 4) === 'lan-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$this->lanNdx = intval($parts[1]);
			$this->loadData();

			if ($viewerMode === 'overview')
				$this->createContent_Overview(1);
			elseif ($viewerMode === 'schema')
				$this->createGraph();
		}
	}

	function createTabs ()
	{
		$tabs = [];
		$this->addLansTabs($tabs);
		$this->toolbar = ['tabs' => $tabs];
	}

	function addLansTabs (&$tabs)
	{
		$enum = $this->db()->query('SELECT * FROM [mac_lan_lans] WHERE docStateMain < 4 ORDER BY [order], shortName')->fetchPairs('ndx', 'shortName');

		foreach ($enum as $lanNdx => $lanName)
		{
			$icon = 'system/iconSitemap';
			$tabs['lan-'.$lanNdx] = ['icon' => $icon, 'text' => $lanName, 'action' => 'load-lan-'.$lanNdx];
		}

		if (count($enum) !== 1)
		{
			$tabs['lan-0'] = ['icon' => 'icon-globe', 'text' => 'Vše', 'action' => 'load-lan-0'];
		}
	}

	protected function initRightTabs ()
	{
		$rt = [
			'viewer-mode-overview' => ['text' => '', 'icon' => 'system/detailOverview', 'action' => 'viewer-mode-overview'],
			//'viewer-mode-old' => ['text' => '', 'icon' => 'system/iconFile', 'action' => 'viewer-mode-old'],
			//'viewer-mode-dashboard-srv' => ['text' => '', 'icon' => 'deviceTypes/server', 'action' => 'viewer-mode-dashboard-server'],
			//'viewer-mode-dashboard-nas' => ['text' => '', 'icon' => 'deviceTypes/nas', 'action' => 'viewer-mode-dashboard-nas'],
			//'viewer-mode-dashboard-lan' => ['text' => '', 'icon' => 'tables/mac.lan.lans', 'action' => 'viewer-mode-dashboard-lan'],
			//'viewer-mode-dashboard-printer' => ['text' => '', 'icon' => 'deviceTypes/printer', 'action' => 'viewer-mode-dashboard-printer'],
			//'viewer-mode-dashboard-ups' => ['text' => '', 'icon' => 'deviceTypes/ups', 'action' => 'viewer-mode-dashboard-ups'],
			'viewer-mode-schema' => ['text' => '', 'icon' => 'system/iconImage', 'action' => 'viewer-mode-schema'],
			//'viewer-mode-overviewnew' => ['text' => '', 'icon' => 'system/detailOverview', 'action' => 'viewer-mode-overviewnew'],
		];
		$this->toolbar['rightTabs'] = $rt;
	}

	public function loadData ()
	{
	}

	public function createContent_Overview($newMode = 0)
	{
		$code = "<h3>tady něco bude...</h3>";
		$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
	}

	public function title() {return FALSE;}

	public function createGraph ()
	{
		$lanSchema = new \mac\lan\dataView\LanSchema($this->app);
		$lanSchema->setRequestParams(['lan' => $this->lanNdx, /*'graphOrientation' => 'landscape'*/]);
		$lanSchema->run();

		$imgUrl = $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->dsRoot . '/' . $lanSchema->data['schemaFileName'];
		$c = "<a href='$imgUrl' target='_blank'><img id='e10-widget-lan-scheme' style='width: 100%; ' src='$imgUrl'></a>";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}
}

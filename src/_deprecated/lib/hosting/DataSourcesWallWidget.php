<?php


namespace lib\hosting;

use \Shipard\UI\Core\WidgetBoard;


class DataSourcesWallWidget extends WidgetBoard
{
	/** @var  \e10\DbTable */
	//var $table;
	/** @var  \e10\DbTable */
	var $tablePartners;


	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if (substr ($this->activeTopTab, 0, 8) === 'partner-')
		{
			$parts = explode ('-', $this->activeTopTab);
			if ($parts[1] === 'all')
				$this->addContentViewer('e10pro.hosting.server.datasources', 'lib.hosting.DataSourcesDashboardViewer', ['partner' => 0, 'viewerMode' => $viewerMode]);
			else
				$this->addContentViewer('e10pro.hosting.server.datasources', 'lib.hosting.DataSourcesDashboardViewer', ['partner' => intval($parts[1]), 'viewerMode' => $viewerMode]);
		}
	}

	public function init ()
	{
		//$this->table = $this->app->table ('e10pro.hosting.server.datasources');
		$this->tablePartners = $this->app->table ('e10pro.hosting.server.partners');

		$this->createTabs();

		parent::init();
	}

	function addDataSourcesTabs (&$tabs)
	{
		//if ($this->app->hasRole('hstng'))
		//	$tabs['partner-all'] = ['icon' => 'icon-user-secret', 'text' => 'Vše', 'action' => 'load-partner-all'];

		$usersPartners = $this->tablePartners->usersPartners();
		foreach ($usersPartners as $p)
		{
			$icon = 'icon-id-badge';
			if (isset($p['icon']) && $p['icon'] !== '')
				$icon = $p['icon'];
			$tabs['partner-'.$p['ndx']] = ['icon' => $icon, 'text' => $p['name'], 'action' => 'load-partner-'.$p['ndx']];
		}
	}

	function createTabs ()
	{
		$tabs = [];
		$this->addDataSourcesTabs($tabs);
		$this->toolbar = ['tabs' => $tabs];
	}

	public function title()
	{
		return FALSE;
	}
}

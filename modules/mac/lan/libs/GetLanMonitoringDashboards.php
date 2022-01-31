<?php

namespace mac\lan\libs;

use e10\Utility, \e10\Application;


/**
 * Class GetLanMonitoringDashboards
 * @package mac\lan\libs
 */
class GetLanMonitoringDashboards extends Utility
{
	public $result = ['success' => 0];
	var $lanCfg = NULL;

	public function run ()
	{
		$serverNdx = intval($this->app->requestPath(4));
		if (!$serverNdx)
			return;
		$serverRecData = $this->db()->query ('SELECT * FROM [mac_lan_devices] WHERE ndx = %i', $serverNdx)->fetch();
		if (!$serverRecData)
			return;

		$lanNdx = $serverRecData['lan'];
		$dashboards = [];

		$lanOverviewData = new \mac\lan\libs\dashboard\OverviewData($this->app());
		//$lanOverviewData = new \mac\lan\libs\LanOverviewData($this->app());
		$lanOverviewData->setLan($lanNdx);
		$lanOverviewData->run();

		foreach ($lanOverviewData->devicesWithDashboards as $deviceNdx)
		{
			$topBar = [];

			$dde = new \mac\lan\libs\DeviceDashboardEngine($this->app());
			$dde->setDevice($deviceNdx);
			$dde->createTopBar($topBar, TRUE);

			foreach ($topBar as $viewId => $dashboardCfg)
			{
				if (!isset($dashboardCfg['code']))
					continue;

				$dashboards['dashboards'][$dashboardCfg['id']] = $dashboardCfg['code'];
			}

			unset($dde);
		}

		$this->result ['cfg'] = $dashboards;
		$this->result ['success'] = 1;

		$dsMode = $this->app()->cfgItem ('dsMode', Application::dsmProduction);
		if ($dsMode === Application::dsmDevel)
		{
			$dir = __APP_DIR__.'/tmp/shipard-node-dashboards';
			if (!is_dir($dir))
				mkdir($dir);

			foreach ($dashboards['dashboards'] as $dashboardId => $dashboardCode)
			{
				$fn = $dir.'/'.$dashboardId.'.html';
				file_put_contents($fn, $dashboardCode);
			}
		}
	}
}

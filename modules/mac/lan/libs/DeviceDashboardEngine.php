<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class DeviceDashboardEngine
 * @package mac\lan\libs
 */
class DeviceDashboardEngine extends Utility
{
	var $deviceNdx = 0;

	/** @var \mac\lan\libs\DeviceInfo */
	var $deviceInfo = NULL;

	var $dataSourceUrl = '';

	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\data\TableSources */
	var $tableSources;
	/** @var \mac\lan\TableLans */
	var $tableLans;

	public function setDevice ($deviceNdx)
	{
		$this->deviceNdx = $deviceNdx;
		$this->deviceInfo = new \mac\lan\libs\DeviceInfo($this->app());
		$this->deviceInfo->setDevice($this->deviceNdx);
	}

	public function createTopBar(&$topBar, $withCode = FALSE)
	{
		foreach ($this->deviceInfo->dataSources as $source)
		{
			if (!isset($this->deviceInfo->macDeviceSubTypeCfg['dashboards']))
				continue;

			$this->dataSourceUrl = $source['url'];

			foreach ($this->deviceInfo->macDeviceSubTypeCfg['dashboards'] as $dashboardViewId => $dashboard)
			{
				$dashboardId = $dashboardViewId . '-' . $this->deviceInfo->deviceRecData['uid'];

				$urlBegin = $this->deviceInfo->deviceMonitoringBaseUrl;
				if ($urlBegin !== '')
				{
					$dashboardUrl = $urlBegin;
					if (substr($dashboardUrl, -1, 1) !== '/')
						$dashboardUrl .= '/';

					$dashboardUrl .= 'dashboards/';
					$dashboardUrl .= $dashboardId . '.html?v='.time();
					$item = ['title' => $dashboard['title'], 'type' => 'iframe', 'url' => $dashboardUrl, 'id' => $dashboardId];

					if ($withCode)
						$item['code'] = $this->createDashboardCode($dashboard);

					$topBar[$dashboardViewId] = $item;
				}
			}

			// -- netdata full view
			if (($this->deviceInfo->deviceRecData['deviceKind'] === 7 || $this->deviceInfo->deviceRecData['deviceKind'] === 70))
			{ // server / node server
					$item = [
							'title' => ['text' => 'Všechna data'],
							'type' => 'iframe',
							'url' => $source['url'],
					];
					$topBar['realtime-full-view'] = $item;
			}
		}

		if (!count($topBar))
		{
			$item = [
				'title' => 'Žádná data',
				'type' => 'nothing',
			];
			$topBar['nothing'] = $item;
		}
	}

	public function createDashboardCode ($dashboard)
	{
		$template = new \mac\lan\libs\DeviceMonitoringTemplate($this->app());
		$template->data['device'] = $this->deviceInfo->info;
		$template->data['dataSourceUrl'] = $this->dataSourceUrl;
		$template->loadTemplate ($dashboard['template']);

		$c = $template->renderTemplate();

		return $c;
	}
}

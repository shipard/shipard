<?php

namespace mac\lan\libs;

use e10\Utility, \e10\Application;


/**
 * Class GetLanMonitoringDashboards
 * @package mac\lan\libs
 */
class GetLanMonitoringSnmp extends Utility
{
	public $result = ['success' => 0];
	var $lanCfg = NULL;

	public function run ()
	{
		$serverNdx = intval($this->app->requestPath(4));
		if (!$serverNdx)
		{
			$this->result['msg'][] = "server param missing in url";
			return;
		}	
		$serverRecData = $this->db()->query ('SELECT * FROM [mac_lan_devices] WHERE ndx = %i', $serverNdx)->fetch();
		if (!$serverRecData)
		{
			$this->result['msg'][] = "invalid server id";
			return;
		}	
		
		$lanNdx = $serverRecData['lan'];
		$snmpData = [];

		$lanOverviewData = new \mac\lan\libs\dashboard\OverviewData($this->app());
		$lanOverviewData->setLan($lanNdx);
		$lanOverviewData->run();

		foreach ($lanOverviewData->devicesWithSnmpRealtime as $deviceNdx)
		{
			$deviceInfo = new \mac\lan\libs\DeviceInfo($this->app());
			$deviceInfo->setDevice($deviceNdx);

			if (!isset($deviceInfo->macDeviceSubTypeCfg['snmpTemplateRealtime']))
				continue;

			$template = new \mac\lan\libs\DeviceMonitoringTemplate($this->app());
			$template->data['device'] = $deviceInfo->info;

			$template->loadTemplate ($deviceInfo->macDeviceSubTypeCfg['snmpTemplateRealtime']);

			$c = $template->renderTemplate();
			$data = json_decode($c, TRUE);
			if ($data)
				$snmpData[] = $data;
		}

		$this->result ['cfg']['realtime'] = $snmpData;
		$this->result ['success'] = 1;
	}
}

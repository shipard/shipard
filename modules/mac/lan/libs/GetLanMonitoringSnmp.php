<?php

namespace mac\lan\libs;
use e10\Utility;


/**
 * Class GetLanMonitoringDashboards
 * @package mac\lan\libs
 */
class GetLanMonitoringSnmp extends Utility
{
	public $result = ['success' => 0];
	var $lanCfg = NULL;
	var $serverNdx = 0;

	public function run ()
	{
		if (!$this->serverNdx)
			$this->serverNdx = intval($this->app->requestPath(4));
		if (!$this->serverNdx)
		{
			$this->result['msg'][] = "server param missing in url";
			return;
		}
		$serverRecData = $this->db()->query ('SELECT * FROM [mac_lan_devices] WHERE ndx = %i', $this->serverNdx)->fetch();
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

		$netdataCfg = "# netdata go.d.plugin configuration for snmp\n";
		$netdataCfg .= "# shipard-node; cfg-v-1;\n";
		$netdataCfg .= "\n";
		$netdataCfg .= "jobs:\n";
		$netdataCfg .= substr(yaml_emit($snmpData), 4, -4);
		$netdataCfg .= "\n\n";

		$this->result ['netdataCfgFile'] = $netdataCfg;
		$this->result ['success'] = 1;
	}
}

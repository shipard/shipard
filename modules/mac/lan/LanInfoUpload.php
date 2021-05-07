<?php

namespace mac\lan;

use e10\Utility;


/**
 * Class LanInfoUpload
 * @package mac\lan
 */
class LanInfoUpload extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$data = json_decode($this->app()->postData(), TRUE);
		if (!$data)
			return;

		$reUploadFrom = $this->app()->testGetParam('reupload-from');
		if ($reUploadFrom !== '')
		{
			$data = $this->checkReUpload($reUploadFrom, $data);
			if (!$data)
				return;
		}

		$serverNdx = intval($data['serverId']);
		if (!$serverNdx)
			return;

		$cfg = $this->db()->query ('SELECT newDataVer FROM [mac_lan_devicesCfgNodes] WHERE device = %i', $serverNdx)->fetch();
		if (!$cfg)
			return;

		$this->result ['cfgDataVer'] = $cfg['newDataVer'];

		// -- load changed scripts
		$q[] = 'SELECT COUNT(*) AS changedDevices';
		array_push ($q,' FROM [mac_lan_devicesCfgScripts] AS cfgScripts');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND cfgScripts.[cfgRequestState] = %i', 1);
		$changedDevices = $this->db()->query($q)->fetch();
		if ($changedDevices && isset($changedDevices['changedDevices']))
			$this->result ['changedDevices'] = intval($changedDevices['changedDevices']);

		$itemKey = 'lanDevicesInfo_'.$serverNdx;
		$lanInfo = $this->app->cache->getDataItem($itemKey);
		if (!$lanInfo)
			$lanInfo = [];

		$lanInfo['t'] = time();

		foreach ($data['data'] as $deviceInfo)
		{
			if (isset($deviceInfo['d']))
			{
				$deviceNdx = $deviceInfo['d'];
				$ip = $deviceInfo['ip'];
				$lanInfo['devices'][$deviceNdx]['ndx'] = $deviceNdx;
				$lanInfo['devices'][$deviceNdx]['addr'][$ip] = $deviceInfo;
			}

			if (isset($deviceInfo['r']))
			{
				$rangeNdx = $deviceInfo['r'];
				$ip = $deviceInfo['ip'];
				$lanInfo['ranges'][$rangeNdx][$ip] = $deviceInfo;
			}
		}

		$this->app->cache->setDataItem($itemKey, $lanInfo);

		$this->result ['success'] = 1;

		// -- watch dog
		/** @var \mac\lan\TableWatchdogs $tableWatchdogs */
		$tableWatchdogs = $this->app()->table('mac.lan.watchdogs');
		$tableWatchdogs->touchFromDevice('node-lan-monitoring', $serverNdx, '');

		$this->reUpload($data);
	}

	function reUpload($data)
	{
		$reUploadTo = $this->app()->cfgItem('mac.lan.reupload.to', NULL);

		if (!$reUploadTo)
			return;

		foreach ($reUploadTo as $ru)
		{
			$url = $ru['url'];
			$url .= $this->app()->requestPath().'?reupload-from='.$ru['from'];

			$ce = new \lib\objects\ClientEngine($this->app());
			$ce->apiKey = $ru['apiKey'];
			$ce->apiCall($url, $data);
		}
	}

	function checkReUpload($reUploadFrom, $data)
	{
		if ($reUploadFrom === '')
			return $data;

		$ruf = $this->app()->cfgItem('mac.lan.reupload.from.'.$reUploadFrom, NULL);
		if (!$ruf)
			return NULL;

		if (!isset($ruf['deviceNdx'][$data['serverId']]))
			return NULL;

		$nd = [
			'info' => $data['info'],
    	'serverId' => $ruf['deviceNdx'][$data['serverId']],
    	'serverVersion' => $data['serverVersion'],
			'data' => [],
		];

		foreach ($data['data'] as $d)
		{
			if (!isset($ruf['ip'][$d['ip']]))
				continue;

			$n = $d;
			$n['ip'] = $ruf['ip'][$d['ip']];
			$n['mac'] = '';

			if (isset($d['d']) && !isset($ruf['deviceNdx'][$d['d']]))
				continue;
			$n['d'] = $ruf['deviceNdx'][$d['d']];

			if (isset($d['r']) && !isset($ruf['ranges'][$d['r']]))
				continue;
			$n['r'] = $ruf['ranges'][$d['r']];

			$nd['data'][] = $n;
		}

		return $nd;
	}
}

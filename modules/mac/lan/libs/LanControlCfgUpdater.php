<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\json;


/**
 * Class LanControlCfgUpdater
 * @package mac\lan\libs
 */
class LanControlCfgUpdater extends Utility
{
	/** @var \mac\lan\libs\LanCfgCreator */
	var $lanCfg;

	var $requestsStates = NULL;

	public function batchUpdate ($batch)
	{
		foreach ($batch as $lanNdx => $devicesList)
		{
			$lanCfg = new \mac\lan\libs\LanCfgCreator($this->app());
			$lanCfg->init();
			$lanCfg->setLan($lanNdx);
			$lanCfg->load();

			foreach ($devicesList as $deviceNdx)
			{
				$this->doOneDevice($deviceNdx, $lanCfg);
			}
		}
	}

	function doOneDevice($deviceNdx, $lanCfg = NULL)
	{
		$sg = new \mac\lan\libs\LanCfgDeviceScriptGenerator($this->app());
		$sg->init();
		$sg->setDevice($deviceNdx, $lanCfg);

		if ($sg->dsg)
		{
			$updateData = [];
			$sg->addToDeviceCfgScripts($updateData);

			$sgInitDevice = new \mac\lan\libs\LanCfgDeviceScriptGenerator($this->app());
			$sgInitDevice->init();
			$sgInitDevice->setDevice($deviceNdx, $lanCfg, TRUE);
			if ($sgInitDevice->dsg)
			{
				$updateData['initScriptVer'] = sha1($sgInitDevice->dsg->script);
			}

			$this->updateDeviceCfgScripts($deviceNdx, $updateData);
		}
	}

	function getDeviceCfgScripts($deviceNdx)
	{
		$exist = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgScripts] WHERE [device] = %i', $deviceNdx)->fetch();
		if ($exist)
			return $exist->toArray();

		$insert = ['device' => $deviceNdx];
		$this->db()->query('INSERT INTO [mac_lan_devicesCfgScripts] ', $insert);

		$exist = $this->db()->query('SELECT * FROM [mac_lan_devicesCfgScripts] WHERE [device] = %i', $deviceNdx)->fetch();
		if ($exist)
			return $exist->toArray();

		return NULL;
	}

	function updateDeviceCfgScripts($deviceNdx, $updateData)
	{
		$currentCfg = $this->getDeviceCfgScripts($deviceNdx);
		if (!$currentCfg)
			return 0;
		$updateData['changed'] = 1;
		if (trim($updateData['liveText']) === '')
			$updateData['changed'] = 0;
		$updateData['liveTimestamp'] = new \DateTime();

		$this->db()->query('UPDATE [mac_lan_devicesCfgScripts] SET ', $updateData, ' WHERE [ndx] = %i', $currentCfg['ndx']);

		return 1;
	}

	function getServerInfo($serverNdx)
	{
		$serverInfo = ['devices' => [], 'requests' => []];

		$q[] = 'SELECT devices.*, lans.mainServerCameras, lans.mainServerLanControl';
		array_push ($q,' FROM [mac_lan_devices] AS devices');
		array_push ($q,' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q,' WHERE devices.[ndx] = %i', $serverNdx, ' AND devices.[docState] != %i', 9800);
		$rows = $this->app()->db->query ($q);

		foreach ($rows as $r)
		{
			$macDeviceCfg = json_decode($r['macDeviceCfg'], TRUE);
			if (!$macDeviceCfg)
				continue;

			$this->lanCfg = new \mac\lan\libs\LanCfgCreator($this->app());
			$this->lanCfg->init();
			$this->lanCfg->setLan($r['lan']);
			$this->lanCfg->load();

			$this->getServerInfoDevices($serverInfo, $r['ndx'], $r['lan'], $r['mainServerLanControl'] === $r['ndx']);
			$this->getServerInfoRequests($serverInfo);
		}

		return $serverInfo;
	}

	function getServerInfoDevices (&$cfgData, $serverNdx, $lanNdx, $isDefaultServer)
	{
		$q [] = 'SELECT devices.*';
		array_push($q, ' FROM [mac_lan_devices] AS [devices]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND devices.[macDeviceType] != %s', '');

		if ($isDefaultServer)
			array_push ($q,'AND (localServer = %i', $serverNdx, ' OR (localServer = %i', 0, ' AND lan = %i))', $lanNdx);
		else
			array_push ($q,'AND localServer = %i', $serverNdx);

		array_push($q, ' AND devices.[docStateMain] <= %i', 2);
		array_push($q, ' ORDER BY devices.ndx');

		$rows = $this->app()->db->query ($q);

		foreach ($rows as $r)
		{
			$macDeviceCfg = json_decode($r['macDeviceCfg'], TRUE);
			if (!$macDeviceCfg)
				continue;

			$macDeviceType = $this->app()->cfgItem('mac.devices.types.'.$r['macDeviceType'], NULL);
			if (!$macDeviceType || !isset($macDeviceType['sgClassId']))
				continue;

			$device = [
				'ndx' => $r['ndx'], 'id' => $r['id'], 'name' => $r ['fullName'],
				'macDeviceType' => $r['macDeviceType'], 'cfg' => $macDeviceCfg
			];

			$device['ipManagement'] = $this->lanCfg->cfg['devices'][$r['ndx']]['ipManagement'];

			$cfgData['devices'][$r['ndx']] = $device;
		}
	}

	function getServerInfoRequests(&$cfgData)
	{
		if (!count($cfgData['devices']))
			return;

		$q [] = 'SELECT scripts.*';
		array_push($q, ' FROM [mac_lan_devicesCfgScripts] AS [scripts]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND scripts.[device] IN %in', array_keys($cfgData['devices']));

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['cfgRequestState'] === 1 && $r['runningVer'] === '')
			{
				$request = ['device' => $r['device'], 'type' => 'getRunningConfig'];
				$cfgData['requests'][] = $request;
			}
			elseif ($r['cfgRequestState'] === 1)
			{
				$request = ['device' => $r['device'], 'type' => 'runScript', 'script' => $r['newText']];
				$cfgData['requests'][] = $request;
			}
		}
	}

	function setRequestState($data)
	{
		$deviceNdx = intval($data['device']);

		$update = [
			'cfgRequestState' => intval($data['state']),
			'cfgLastRequestResult' => intval($data['result']),
			'cfgLastRequestResultLog' => $data['resultLog'],
			'cfgLastRequestResultTimestamp' => new \DateTime(),
		];

		if (isset($data['runningConfig']))
		{
			$update['runningText'] = $data['runningConfig'];

			$p = new \mac\lan\libs\LanControlCfgRCParser($this->app);
			$p->setDevice($deviceNdx);
			$p->cfgParser->setSrcScript($data['runningConfig']);
			$p->cfgParser->parse();
			$update['runningData'] = json::lint($p->cfgParser->parsedData);
			$update['runningVer'] = sha1($update['runningText'].$update['runningData']);
			$update['runningTimestamp'] = new \DateTime();

			$update['inDevInitScriptVer'] = $p->cfgParser->inDevShipardCfgVer;

			$update['newText'] = '';
			$update['newData'] = '[]';
			$update['newVer'] = sha1($update['newText'].$update['newData']);
			$update['newTimestamp'] = new \DateTime();
		}

		$requestRecData = $this->db()->query('SELECT ndx FROM mac_lan_devicesCfgScripts WHERE device = %i', $deviceNdx)->fetch();
		if (!$requestRecData)
			return;

		$this->db()->query('UPDATE mac_lan_devicesCfgScripts SET ', $update, ' WHERE ndx = %i', $requestRecData['ndx']);

		$lcu = new \mac\lan\libs\LanControlCfgUpdater($this->app());
		$lcu->batchUpdate([$deviceNdx]);
	}

	function getRequestsStates()
	{
		$this->requestsStates = ['cnt' => 0, 'cntChanged' => 0, 'table' => [], 'labels' => []];

		$this->requestsStates['header'] = ['#' => '#', 'deviceId' => 'ID', 'deviceName' => 'Server', 'status' => 'Stav'];

		$this->requestsStates['title'] = [
			['text' => 'Požadavky na změnu konfigurace aktivních prvků', 'class' => 'h1'],
		];

		// -- load changed settings
		$q[] = 'SELECT cfgScripts.*, devices.[fullName] AS deviceName, devices.[id] AS deviceId';
		array_push ($q,' FROM [mac_lan_devicesCfgScripts] AS cfgScripts');
		array_push ($q,' LEFT JOIN mac_lan_devices AS devices ON cfgScripts.device = devices.ndx');
		array_push ($q,' WHERE 1');
		array_push ($q,' AND (cfgScripts.[changed] = %i', 1, ' OR cfgScripts.[cfgRequestState] != %i)', 5);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$labelTexts = [];
			$labelClass = 'label-default';
			$item = ['deviceId' => $r['deviceId'], 'deviceName' => $r['deviceName'], 'status' => []];

			switch ($r['cfgRequestState'])
			{
				case 0: $labelTexts[] = 'Neznámý stav zařízení'; $item['status'][] = ['text' => $labelTexts[0], 'class' => 'label label-default']; $labelClass = 'label-danger';break;
				case 1: $labelTexts[] = 'Čeká se na odeslání'; $item['status'][] = ['text' => $labelTexts[0], 'class' => 'label label-default']; $labelClass = 'label-info';break;
				case 2: $labelTexts[] = 'Odesláno k nastavení'; $item['status'][] = ['text' => $labelTexts[0], 'class' => 'label label-default']; $labelClass = 'label-primary';break;
				case 3: $labelTexts[] = 'Čeká na nastavení'; $item['status'][] = ['text' => $labelTexts[0], 'class' => 'label label-default']; $labelClass = 'label-success';break;
				case 4: $labelTexts[] = 'Nastavuje se'; $item['status'][] = ['text' => $labelTexts[0], 'class' => 'label label-default']; $labelClass = 'label-success';break;
			}

			if ($r['changed'])
			{
				$labelTexts[] = 'Lokální změny';
				$item['status'][] = ['text' => 'Lokální změny', 'class' => 'label label-default'];
				$this->requestsStates['cntChanged']++;

				$item['status'] = [];
				$item['status'][] = ['text' => $r['newText'], 'class' => 'label label-default'];

				$item['changes'] = ['deviceNdx' => $r['device'], 'title' => $r['deviceName'], 'text' => $r['liveText']];
			}

			$this->requestsStates['cnt']++;
			$this->requestsStates['table'][] = $item;
			$this->requestsStates['labels'][] = ['text' => $r['deviceId'], 'suffix' => implode ('; ', $labelTexts), 'class' => 'label '.$labelClass];
		}
	}

	public function confirmChanges()
	{
		$q [] = 'SELECT scripts.*';
		array_push($q, ' FROM [mac_lan_devicesCfgScripts] AS [scripts]');
		array_push ($q,' LEFT JOIN mac_lan_devices AS devices ON scripts.device = devices.ndx');
		array_push($q, ' WHERE 1');
		array_push ($q,' AND devices.docState != %i', 9800);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['cfgRequestState'] === 0 && $r['runningVer'] === '')
			{
				$update = ['cfgRequestState' => 1, 'cfgLastRequestResult' => 0, 'cfgLastRequestResultTimestamp' => new \DateTime()];
				$this->db()->query('UPDATE [mac_lan_devicesCfgScripts] SET ', $update, ' WHERE ndx = %i', $r['ndx']);
			}
			elseif ($r['cfgRequestState'] === 5 && $r['changed'] == 1)
			{
				$this->db()->query('UPDATE [mac_lan_devicesCfgScripts] SET newText = liveText, newData = liveData, newVer = liveVer, ',
					'newTimestamp = NOW(), changed = 0, cfgRequestState = 1',
					' WHERE [ndx] = %i', $r['ndx']);
			}
		}
	}

	public function setToReloadRunningConf()
	{
		$q [] = 'SELECT scripts.*';
		array_push($q, ' FROM [mac_lan_devicesCfgScripts] AS [scripts]');
		array_push ($q,' LEFT JOIN mac_lan_devices AS devices ON scripts.device = devices.ndx');
		array_push($q, ' WHERE 1');
		array_push ($q,' AND devices.docState != %i', 9800);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$update = ['cfgRequestState' => 0, 'runningVer' => '', 'changed' => 0];
			$this->db()->query('UPDATE [mac_lan_devicesCfgScripts] SET ', $update, ' WHERE ndx = %i', $r['ndx']);
		}
	}
}

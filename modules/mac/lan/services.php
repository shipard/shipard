<?php

namespace mac\lan;
use e10\json, e10\utils;


/**
 * Class ModuleServices
 * @package mac\lan
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$s [] = ['end' => '2019-01-31', 'sql' => "UPDATE mac_lan_devicesPorts SET portRole = 10 WHERE vlan != 0 AND portKind IN (5, 6) AND portRole = 0"];
		$this->doSqlScripts ($s);
		$this->repairUID();
	}

	function repairUID()
	{
		$rows = $this->app->db()->query('SELECT ndx FROM mac_lan_devices WHERE [uid] = %s', '');
		foreach ($rows as $r)
		{
			$this->app->db()->query('UPDATE mac_lan_devices SET [uid] = %s', utils::createToken(32),
				' WHERE ndx = %i', $r['ndx']);
		}
	}

	public function refreshDevicesProperties()
	{
		$e = new \mac\lan\DevicePropertiesEngine($this->app);
		$e->doUnchecked(FALSE);
		$e->doInstallPackages();
	}

	public function dataSourceStatsCreate()
	{
		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();

		// -- lan devices
		$dsStats->data['extModules']['mac']['lan']['created'] = new \DateTime();
		$dsStats->data['extModules']['mac']['lan']['countDevices']['ALL'] = 0;
		$rows = $this->app->db()->query (
			'SELECT [deviceKind], COUNT(*) AS cnt FROM mac_lan_devices', ' WHERE docState = %i', 4000, ' GROUP by deviceKind');
		foreach ($rows as $r)
		{
			$dsStats->data['extModules']['mac']['lan']['countDevices']['ALL'] += $r['cnt'];
			$dsStats->data['extModules']['mac']['lan']['countDevices'][$r['deviceKind']] = $r['cnt'];
		}

		json::polish($dsStats->data['extModules']['mac']['lan']);

		$dsStats->saveToFile();
	}

	public function onStats()
	{
		$this->refreshDevicesProperties();

		$this->dataSourceStatsCreate();
	}

	function parseRunningConf()
	{
		$q[] = 'SELECT * FROM [mac_lan_devicesCfgScripts]';
		array_push($q, ' WHERE 1');
		//array_push($q, ' AND device = %i', 2);

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			$p = new \mac\lan\libs\LanControlCfgRCParser($this->app);
			$p->setDevice($r['device']);
			$p->cfgParser->setSrcScript($r['runningText']);
			$p->cfgParser->parse();
			$p->saveTo();
		}
	}

	function setToReloadRunningConf()
	{
		$lcu = new \mac\lan\libs\LanControlCfgUpdater($this->app);
		$lcu->setToReloadRunningConf();
	}

	function lanAlertsUpdater()
	{
		$lau = new \mac\lan\libs\alerts\AlertsUpdater($this->app);
		$lau->init();
		$lau->runAll();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'lan-control-parse-running-conf': return $this->parseRunningConf();
			case 'lan-control-reload-running-conf': return $this->setToReloadRunningConf();
			case 'lan-alerts-updater': return $this->lanAlertsUpdater();
		}

		parent::onCliAction($actionId);
	}

	function onCronEver()
	{
		$this->lanAlertsUpdater();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'stats': $this->onStats(); break;
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}
}

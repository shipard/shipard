<?php

namespace mac\lan\libs\alerts;


use e10\Utility;
use E10\utils;


/**
 * Class LanAlertsDownload
 * @package mac\lan\libs\alerts
 */
class LanAlertsDownload extends Utility
{
	var $alertsTypes;
	var $alertsScopes;
	var $watchdogs;

	public $result = ['success' => 0];

	var $alerts = [];
	var $globalBadges = '';

	var $alertsCount = [];

	function init()
	{
		$this->alertsTypes = $this->app()->cfgItem('mac.lan.alerts.types');
		$this->alertsScopes = $this->app()->cfgItem('mac.lan.alerts.scopes');
		$this->watchdogs = $this->app()->cfgItem('mac.lan.watchdogs');
	}

	function loadAlerts()
	{
		$q = [];
		array_push($q, 'SELECT [ad].*,');
		array_push($q, ' [alerts].alertType AS [alertType], [alerts].alertSubtype AS [alertSubtype], [alerts].alertScope AS [alertScope],');
		array_push($q, ' devices.fullName AS [deviceFullName], devices.id AS [deviceId], devices.deviceKind AS [deviceKind]');
		array_push($q, ' FROM [mac_lan_alertsDevices] AS [ad]');
		array_push($q, ' LEFT JOIN [mac_lan_alerts] AS [alerts] ON ad.alert = alerts.ndx');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS [devices] ON ad.device = devices.ndx');
		array_push($q, ' WHERE 1');

		$t = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$deviceNdx = $r['device'];
			$alertScope = $r['alertScope'];
			$alertScopeCfg = $this->alertsScopes[$alertScope];
			$alertScopeId = $alertScopeCfg['id'];

			if (!isset($this->alerts['scopes'][$alertScopeId]))
			{
				$this->alerts['scopes'][$alertScopeId] = ['alerts' => [], 'badges' => '', 'content' => '',];
			}

			$alertType = $r['alertType'];
			$alertTypeCfg = $this->alertsTypes[$alertType];
			$alertTypeId = strval($alertType);

			if (!isset($this->alerts['scopes'][$alertScopeId]['alerts'][$alertTypeId]))
			{
				$this->alerts['scopes'][$alertScopeId]['alerts'][$alertTypeId] = [
					'title' => $alertTypeCfg['fn'],
					'content' => []
				];
			}

			$this->alerts['scopes'][$alertScopeId]['alerts'][$alertTypeId]['devices'][] = ['fn' => $r['deviceFullName']];

			$alertSeverity = isset($alertTypeCfg['severity']) ? $alertTypeCfg['severity'] : 'none';
			if ($alertSeverity !== 'none')
			{
				if (!isset($this->alertsCount[$alertSeverity]))
				{
					$this->alertsCount[$alertSeverity] = ['title' => $alertTypeCfg['severityTitle'], 'icon' => $alertTypeCfg['severityIcon'], 'cnt' => 1];
				}
				else
					$this->alertsCount[$alertSeverity]['cnt']++;
			}
		}
	}

	public function renderAlerts()
	{
		if (!isset($this->alerts['scopes']))
			return;

		foreach ($this->alerts['scopes'] as $alertScopeId => &$alertScope)
		{
			$table = [];
			foreach ($alertScope['alerts'] as $alertId => &$alert)
			{
				$badge = ['text' => $alert['title'], 'suffix' => strval(count($alert['devices'])), 'class' => 'label label-default'];
				$alertScope['badges'] .= ' ' . $this->app()->ui()->renderTextLine($badge);

				$table[] = ['title' => $alert['title'], '_options' => ['class' => 'subheader']];

				foreach ($alert['devices'] as $device)
				{
					$table[] = ['title' => $device['fn']];
				}

			}
			$header = ['title' => 'Zařízení'];
			$alertScope['content'] = \e10\renderTableFromArray ($table, $header, [], $this->app());
		}

		foreach ($this->alertsCount as $asId => $as)
		{
			$badge = ['text' => '', 'title' => $as['title'], 'icon' => $as['icon'], 'suffix' => strval($as['cnt']), 'class' => 'label label-default'];
			$this->globalBadges .= $this->app()->ui()->renderTextLine($badge);
		}
	}

	public function run ()
	{
		$this->init();
		$this->loadAlerts();
		$this->renderAlerts();

		$this->result ['lanAlerts'] = $this->alerts;
		$this->result ['globalBadges'] = $this->globalBadges;
		$this->result ['success'] = 1;
	}
}

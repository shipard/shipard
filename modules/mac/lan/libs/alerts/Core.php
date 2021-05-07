<?php

namespace mac\lan\libs\alerts;

use e10\Utility, \e10\utils, \e10\json, \mac\lan\libs\alerts\AlertsUtils;


/**
 * Class Core
 * @package mac\lan\libs\alerts
 */
class Core extends Utility
{
	var $alertType = AlertsUtils::atUnknown;

	/** @var \DateTime */
	var $now = NULL;
	var $devicesKinds;

	var $detectedAlerts = [];

	public function init()
	{
		$this->now = new \DateTime();
		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
	}

	function detectAll()
	{
		$this->saveDetectedAlerts();
	}

	function addDeviceAlert($deviceNdx, $deviceKind, $alertType, $alertSubtype)
	{
		$dk = $this->devicesKinds[$deviceKind];
		$scope = $dk['alertsScope'];

		if (!isset($this->detectedAlerts[$alertType][$alertSubtype][$scope]))
		{
			$this->detectedAlerts[$alertType][$alertSubtype][$scope] = ['devices' => []];
		}

		$this->detectedAlerts[$alertType][$alertSubtype][$scope]['devices'][] = $deviceNdx;
	}

	public function saveDetectedAlerts()
	{
		$usedPks = [];
		// -- insert / update detected alerts
		foreach ($this->detectedAlerts as $alertType => $alertsForSubtype)
		{
			foreach ($alertsForSubtype as $alertSubtype => $alertsForScope)
			{
				foreach ($alertsForScope as $alertScope => $a)
				{
					$exist = $this->db()->query('SELECT ndx FROM [mac_lan_alerts] WHERE [alertType] = %i', $alertType,
						' AND [alertSubtype] = %i', $alertSubtype, ' AND [alertScope] = %i', $alertScope)->fetch();

					if (!$exist)
					{ // insert
						$newAlertItem = [
							'alertType' => $alertType, 'alertSubtype' => $alertSubtype, 'alertScope' => $alertScope,
						];
						$this->db()->query('INSERT INTO [mac_lan_alerts] ', $newAlertItem);
						$alertNdx = intval ($this->app()->db()->getInsertId ());

						foreach ($a['devices'] as $deviceNdx)
						{
							$newDeviceItem = ['alert' => $alertNdx, 'device' => $deviceNdx];
							$this->db()->query('INSERT INTO [mac_lan_alertsDevices] ', $newDeviceItem);
						}
					}
					else
					{
						$alertNdx = $exist['ndx'];

						$devicesPks = [];
						foreach ($a['devices'] as $deviceNdx)
						{
							$existDevice = $this->db()->query('SELECT * FROM [mac_lan_alertsDevices] WHERE [alert] = %i', $alertNdx, ' AND [device] = %i', $deviceNdx)->fetch();
							if (!$existDevice)
							{
								$newDeviceItem = ['alert' => $alertNdx, 'device' => $deviceNdx];
								$this->db()->query('INSERT INTO [mac_lan_alertsDevices] ', $newDeviceItem);
								$devicesPks[] = intval ($this->app()->db()->getInsertId ());
							}
							else
							{
								$devicesPks[] = $existDevice['ndx'];
							}
						}

						if (count($devicesPks))
							$this->db()->query('DELETE FROM [mac_lan_alertsDevices] WHERE [alert] = %i', $alertNdx, ' AND [ndx] NOT IN %in', $devicesPks);
						else
							$this->db()->query('DELETE FROM [mac_lan_alertsDevices] WHERE [alert] = %i', $alertNdx);
					}
					$usedPks[] = $alertNdx;
				}
			}

			// -- delete unused
			if (count($usedPks))
			{
				$this->db()->query('DELETE FROM [mac_lan_alerts] WHERE [alertType] = %i', $alertType, ' AND [ndx] NOT IN %in', $usedPks);

				$this->db()->query('DELETE FROM [mac_lan_alertsDevices] WHERE ',
					' NOT EXISTS (SELECT ndx FROM mac_lan_alerts WHERE mac_lan_alerts.ndx = alert)');
			}
		}

		// -- delete unused
		if (count($usedPks))
		{
			$this->db()->query('DELETE FROM [mac_lan_alerts] WHERE [alertType] = %i', $this->alertType, ' AND [ndx] NOT IN %in', $usedPks);
		}
		else
		{
			$this->db()->query('DELETE FROM [mac_lan_alerts] WHERE [alertType] = %i', $this->alertType);
		}
		$this->db()->query('DELETE FROM [mac_lan_alertsDevices] WHERE ',
			' NOT EXISTS (SELECT ndx FROM mac_lan_alerts WHERE mac_lan_alerts.ndx = alert)');
	}
}

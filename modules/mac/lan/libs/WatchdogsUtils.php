<?php

namespace mac\lan\libs;

use Dibi\DateTime;
use e10\Utility, \e10\utils, \e10\json;


/**
 * Class WatchdogsUtils
 * @package mac\lan\libs
 */
class WatchdogsUtils extends Utility
{
	public function devicesBadges($devicesPks, &$dst)
	{
		$watchdogs = $this->app()->cfgItem('mac.lan.watchdogs');
		$now = new DateTime();

		$q = [];
		array_push($q, 'SELECT wds.*');
		array_push($q, ' FROM [mac_lan_watchdogs] AS [wds]');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS [devices] ON wds.device = devices.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [wds].device IN %in', $devicesPks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$wd = $watchdogs[$r['watchdog']];

			$badge = [
				'text' => $wd['fn'], 'class' => 'label label-default',
				'icon' => 'icon-heartbeat',
			];
			$dst[$r['device']]['all'][] = $badge;

			if (!isset($dst[$r['device']]['first']))
			{
				$badge = [
					'text' => utils::dateDiffShort($r['time1'], $now),
					'time' => $r['time1'],
					'title' => $wd['fn'], 'class' => 'label label-default',
					'icon' => 'icon-heartbeat',
				];
				$dst[$r['device']]['first'] = $badge;
			}
			else
			{
				if ($r['time1'] > $dst[$r['device']]['first']['time'])
				{
					$dst[$r['device']]['first']['time'] = $r['time1'];
					$dst[$r['device']]['first']['text'] = utils::dateDiffShort($r['time1'], $now);
				}
			}
		}
	}
}

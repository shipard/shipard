<?php

namespace mac\swlan\libs;
use e10\Utility, mac\swcore\libs\SWUtils;


/**
 * Class SWDevicesUtils
 * @package mac\swlan\libs
 */
class SWDevicesUtils extends Utility
{
	public function devicesOSBadges($devicesPks, &$dst)
	{
		$osFamily = $this->app()->cfgItem('mac.swcore.osFamily');

		$q = [];
		array_push($q, 'SELECT devicesSW.*,');
		array_push($q, ' [sw].fullName AS swName, [sw].osFamily,');
		array_push($q, ' [swVersions].versionName, [swVersions].versionNameShort, [swVersions].versionNumber, [swVersions].lifeCycle AS versionLifeCycle');
		array_push($q, ' FROM [mac_swlan_devicesSW] AS devicesSW');
		array_push($q, ' LEFT JOIN [mac_sw_sw] AS [sw] ON devicesSW.sw = sw.ndx');
		array_push($q, ' LEFT JOIN [mac_sw_swVersions] AS [swVersions] ON devicesSW.swVersion = swVersions.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [devicesSW].device IN %in', $devicesPks);
		array_push($q, ' AND [sw].swClass = %i', 1);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$badge = [
				'text' => $r['swName'], 'class' => '',
				'icon' => $osFamily[$r['osFamily']]['icon']
			];
			if ($r['swVersion'])
			{
				if ($r['versionNameShort'] !== '')
					$badge['suffix'] = $r['versionNameShort'];
				elseif ($r['versionName'] !== '')
					$badge['suffix'] = $r['versionName'];
				elseif ($r['versionNumber'] !== '')
					$badge['suffix'] = $r['versionNumber'];

				if ($r['versionLifeCycle'] == SWUtils::lcActive)
					$badge['class'] = 'label label-default';
				elseif ($r['versionLifeCycle'] == SWUtils::lcObsolete)
					$badge['class'] = 'label label-warning';
				elseif ($r['versionLifeCycle'] == SWUtils::lcEnded)
					$badge['class'] = 'label label-danger';
				elseif ($r['versionLifeCycle'] == SWUtils::lcPreliminary)
					$badge['class'] = 'label label-info';
			}
			else
			{
				$badge['suffix'] = '???';
				$badge['class'] = 'label label-danger';
			}
			$dst[$r['device']][] = $badge;
		}
	}
}

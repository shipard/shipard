<?php

namespace mac\vs;
use \e10\Utility, \e10\utils;


/**
 * Class StartMenu
 * @package mac\vs
 */
class StartMenu extends Utility
{
	public function create (&$content)
	{
		$tableZones = $this->app->table ('mac.base.zones');
		$usersZones = $tableZones->usersZones('vs-main');
		$cnt = 0;
		foreach ($usersZones as $z)
		{
			$tileInfo = [
				'name' => $z['sn'],
				't1' => $z['sn'], 'class' => 'e10-small',
				'icon' => 'deviceTypes/camera',
				'object' => 'widget',
				'order' => 100000 + $cnt,
				'path' => 'widget/mac.vs.WidgetLive/zone-'.$z['ndx'],
			];

			$content ['start']['items']['zone-'.$z['ndx']] = $tileInfo;
			$cnt++;
		}
	}
}

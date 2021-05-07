<?php

namespace lib\cfg;

use e10\utils, \lib\cfg\PanelCreator;


/**
 * Class SettingsPanelCreator
 * @package lib\cfg
 */
class SettingsPanelCreator extends PanelCreator
{
	public function run ()
	{
		$appOptions = \e10\sortByOneKey($this->app()->appOptions(), 'order', TRUE);
		$groups = \E10\sortByOneKey($this->app()->cfgItem ('e10.appOptions.groups', []), 'order', TRUE);
		foreach ($groups as $groupId => $group)
		{
			$groupCfg = ['groupTitle' => $group['title'], 'items' => []];
			forEach ($appOptions as $id => $c)
			{
				if ($c['group'] !== $groupId)
					continue;
				if (!utils::enabledCfgItem($this->app(), $c))
					continue;

				if ($c ['type'] === 'viewer')
				{
					$c['object'] = 'viewer';
					if ($this->app()->checkAccess($c) === 0)
						continue;
					$si = [
						"t1" => $c['name'],
						'object' => "viewer", "table" => $c['table'], "viewer" => $c['viewer'], "icon" => $c['icon'], "order" => $c['order']
					];
					$groupCfg['items'][] = $si;
				}
				else
				{
					if (!$this->app()->hasRole('admin'))
						continue;
				}

			}
			$this->panelContent['items'][] = $groupCfg;
		}
	}
}


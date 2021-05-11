<?php


namespace e10\base\libs;


class Utils
{
	static function classificationParams (\Shipard\Table\DbTable $table)
	{
		$clsfItems = $table->app()->cfgItem ('e10.base.clsf');
	
		$params = [];
		$groups = $table->app()->cfgItem ('e10.base.clsfGroups', []);
		forEach ($groups as $key => $group)
		{
			if (isset ($group ['tables']) && !in_array ($table->tableId(), $group ['tables']))
				continue;
	
			$p = ['id' => $key, 'name' => isset($group['label']) ? $group['label'] : $group['name'], 'items' => []];
			$grpItems = $table->app()->cfgItem ('e10.base.clsf.'.$key, []);
			foreach ($grpItems as $itmNdx => $itm)
			{
				$clsfItem = $clsfItems [$key][$itmNdx];
	
				$p['items'][$itmNdx] = ['id' => $itmNdx, 'title' => $itm ['name']];
				if (isset($clsfItem['css']))
					$p['items'][$itmNdx]['css'] = $clsfItem['css'];
			}
			if (count($p['items']))
				$params[] = $p;
		}
		return $params;
	}

	static function addClassificationParamsToPanel(\Shipard\Table\DbTable $table, \Shipard\Viewer\TableViewPanel $panel)
	{
		$clsf = self::classificationParams ($table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $params];
		}
	}
}

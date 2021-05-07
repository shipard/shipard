<?php


namespace lib\wkf;

use e10\Utility;


/**
 * Class MapEngine
 * @package lib\wkf
 */
class MapEngine extends Utility
{
	var $mapNdx = 0;
	var $mapItems = [];
	var $pins = [];

	public function setMap ($mapNdx)
	{
		$this->mapNdx = intval ($mapNdx);

		$q[] = 'SELECT * FROM [e10pro_wkf_mapsItems]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [map] = %i', $this->mapNdx);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$i = ['mapItem' => $r['mapItem'], 'mapItemId' => $r['mapItemId']];
			$this->mapItems[] = $i;
		}
	}

	function loadMapPins ()
	{
		foreach ($this->mapItems as $mi)
		{
			$mapItemDef = $this->app()->cfgItem ('e10.maps.mapsItems.'.$mi['mapItem'], NULL);
			if (!$mapItemDef)
				continue;

			$miObject = $this->app()->createObject($mapItemDef['classId']);
			if (!$miObject)
				continue;

			$miObject->setMapsItem($mi);
			$miObject->addPins ($this->pins);
		}
	}
}


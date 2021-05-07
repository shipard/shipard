<?php

namespace lib\geoTags;

use \e10\Utility;


/**
 * Class GeoTagsEngine
 * @package lib\geoTags
 */
class GeoTagsEngine extends Utility
{
	var $srcTableNdx = 0;
	var $srcRecNdx = 0;

	var $lat = 0.0;
	var $lon = 0.0;
	var $latMin = 0.0;
	var $lonMin = 0.0;
	var $latMax = 0.0;
	var $lonMax = 0.0;

	var $latMaxDiff = 0.005;
	var $lonMaxDiff = 0.005;

	var $addresses = NULL;

	public function setCoordinates ($lat, $lon)
	{
		$this->lat = $lat;
		$this->lon = $lon;

		$this->latMin = $this->lat - $this->latMaxDiff;
		$this->latMax = $this->lat + $this->latMaxDiff;

		$this->lonMin = $this->lon - $this->lonMaxDiff;
		$this->lonMax = $this->lon + $this->lonMaxDiff;
	}

	public function setSourceRec ($tableNdx, $recNdx)
	{
		$this->srcTableNdx = $tableNdx;
		$this->srcRecNdx = $recNdx;
	}

	function searchAddresses()
	{
		$q[] = 'SELECT * FROM [e10_persons_address]';
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND [locState] = %i', 1);
		array_push ($q, ' AND (lat < %f', $this->latMax, ' AND lat > %f', $this->latMin, ')');
		array_push ($q, ' AND (lon < %f', $this->lonMax, ' AND lon > %f', $this->lonMin, ')');

		$this->addresses = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dstTableNdx = $this->app()->model()->tableNdx ($r['tableid']);

			$item = [
				'srcTable' => $this->srcTableNdx, 'srcRec' => $this->srcRecNdx,
				'dstTable' => $dstTableNdx, 'dstRec' => $r['recid'],
				'locAddress' => $r['ndx'], 'locHash' => $r['locHash'],
				'tagType' => 0
			];

			$this->addresses[] = $item;
		}
	}

	function save()
	{
		if (!$this->srcRecNdx)
			return;

		if (!$this->addresses)
			return;

		foreach ($this->addresses as $a)
		{
			$this->db()->query ('INSERT INTO [e10_base_geoTags] ', $a);
		}
	}

	public function run()
	{
		$this->searchAddresses();
		$this->save();
	}
}

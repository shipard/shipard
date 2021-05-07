<?php

namespace lib\wkf;

use e10\Utility;


/**
 * Class MapsItems
 * @package lib\wkf
 */
class MapsItems extends Utility
{
	var $mapsItem = NULL;

	public function setMapsItem ($mapsItem)
	{
		$this->mapsItem = $mapsItem;
	}

	public function enumItems()
	{
		return [];
	}

	public function addPins (&$pins)
	{
	}
}
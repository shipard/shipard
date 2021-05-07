<?php

namespace e10\base;
use \e10\DbTable;


/**
 * Class TableGeoTags
 * @package E10\Base
 */
class TableGeoTags extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.geoTags', 'e10_base_geoTags', 'Geografické značky');
	}
}

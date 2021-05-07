<?php

namespace e10\world;

use \e10\DbTable;


/**
 * Class TableCountryTerritories
 * @package e10\world
 */
class TableCountryTerritories extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.countryTerritories', 'e10_world_countryTerritories', 'Oblasti zemí');
	}
}

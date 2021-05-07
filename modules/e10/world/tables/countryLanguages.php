<?php

namespace e10\world;

use \e10\DbTable;


/**
 * Class TableCountryLanguages
 * @package e10\world
 */
class TableCountryLanguages extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.countryLanguages', 'e10_world_countryLanguages', 'Jazyky zemí');
	}
}

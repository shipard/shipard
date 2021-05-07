<?php

namespace e10\world;

use \e10\DbTable;


/**
 * Class TableCountryCurrencies
 * @package e10\world
 */
class TableCountryCurrencies extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.countryCurrencies', 'e10_world_countryCurrencies', 'Měny zemí');
	}
}

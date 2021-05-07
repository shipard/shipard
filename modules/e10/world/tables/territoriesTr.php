<?php

namespace e10\world;

use \e10\DbTable;


/**
 * Class TableTerritoriesTr
 * @package e10\world
 */
class TableTerritoriesTr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.territoriesTr', 'e10_world_territoriesTr', 'Oblasti - Lokalizace');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $h;
	}
}

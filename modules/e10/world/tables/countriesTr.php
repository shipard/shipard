<?php

namespace e10\world;

use \E10\DbTable;


/**
 * Class TableCountriesTr
 * @package e10\world
 */
class TableCountriesTr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.countriesTr', 'e10_world_countriesTr', 'Země - Lokalizace');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['nameCommon']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['nameOfficial']];

		return $h;
	}
}

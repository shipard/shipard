<?php

namespace e10\world;
use \e10\DbTable;


/**
 * Class TableCurrenciesTr
 * @package e10\world
 */
class TableCurrenciesTr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.currenciesTr', 'e10_world_currenciesTr', 'Měny - Lokalizace');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['namePlural']];

		return $h;
	}
}

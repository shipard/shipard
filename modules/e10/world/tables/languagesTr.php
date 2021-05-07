<?php

namespace e10\world;

use \e10\DbTable;


/**
 * Class TableLanguagesTr
 * @package e10\world
 */
class TableLanguagesTr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.world.languagesTr', 'e10_world_languagesTr', 'Jazyky - Lokalizace');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $h;
	}
}

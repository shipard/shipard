<?php

namespace hosting\core;
use \e10\DbTable;

/**
 * Class TableDSUsersSummary
 */
class TableDSUsersSummary extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.dsUsersSummary', 'hosting_core_dsUsersSummary', 'Přehledy pro uživatele');
	}
}

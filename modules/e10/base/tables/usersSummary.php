<?php

namespace e10\base;
use \e10\DbTable;


/**
 * Class TableUsersSummary
 * @package e10\base
 */
class TableUsersSummary extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.usersSummary', 'e10_base_usersSummary', 'Přehledy pro uživatele');
	}
}

<?php

namespace e10pro\hosting\server;

use \e10\DbTable;


/**
 * Class TableUdsSummary
 * @package e10pro\hosting\server
 */
class TableUdsSummary extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.server.udsSummary', 'e10pro_hosting_server_udsSummary', 'Přehledy pro uživatele');
	}
}


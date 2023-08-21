<?php

namespace e10\users;
use \Shipard\Table\DbTable;


/**
 * Class TableSessions
 */
class TableSessions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.users.sessions', 'e10_users_sessions', 'Sessions');
	}
}


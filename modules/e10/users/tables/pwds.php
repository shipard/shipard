<?php

namespace e10\users;
use \Shipard\Table\DbTable;


/**
 * Class TablePwds
 */
class TablePwds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.users.pwds', 'e10_users_pwds', 'Hesla');
	}
}


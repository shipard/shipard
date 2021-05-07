<?php

namespace e10\web;

use e10\DbTable;


/**
 * Class TableWuPasswords
 * @package e10\web¨
 */
class TableWuPasswords extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.wuPasswords', 'e10_web_wuPasswords', 'Hesla uživatelů webu');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec($recData);
	}
}

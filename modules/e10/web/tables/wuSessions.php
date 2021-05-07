<?php

namespace e10\web;

use e10\DbTable;


/**
 * Class TableWuSessions
 * @package e10\web
 */
class TableWuSessions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.wuSessions', 'e10_web_wuSessions', 'Sezení webu');
	}
}


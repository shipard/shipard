<?php

namespace demo\core;

use \E10\DbTable;


/**
 * Class TableTasks
 */
class TableTasks extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('demo.core.tasks', 'demo_core_tasks', 'Generování demonstračních dat');
	}
}

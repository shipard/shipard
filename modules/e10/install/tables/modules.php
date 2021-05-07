<?php

namespace e10\install;

use \e10\DbTable;


/**
 * Class TablePackages
 * @package e10\install
 */
class TableModules extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10.install.modules', 'e10_install_modules', 'Instalované moduly');
	}
}

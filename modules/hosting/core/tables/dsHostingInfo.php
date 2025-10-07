<?php

namespace hosting\core;
use \Shipard\Table\DbTable;


/**
 * Class TableDSHostingInfo
 */
class TableDSHostingInfo extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('hosting.core.dsHostingInfo', 'hosting_core_dsHostingInfo', 'Informace o zdroji dat');
	}
}

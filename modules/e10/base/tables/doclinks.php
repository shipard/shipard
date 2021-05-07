<?php

namespace E10\Base;

use \E10\DbTable;


/**
 * Class TableDocLinks
 */
class TableDoclinks extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName("e10.base.doclinks", "e10_base_doclinks", "Vazby dokumentů");
	}
}

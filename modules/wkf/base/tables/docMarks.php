<?php

namespace wkf\base;

use \e10\DbTable;


/**
 * Class TableDocMarks
 * @package wkf\base
 */
class TableDocMarks extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('wkf.base.docMarks', 'wkf_base_docMarks', 'Značky dokumentů ve workflow', 0);
	}
}


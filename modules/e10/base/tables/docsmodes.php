<?php

namespace E10\Base;

use \E10\DbTable;

/**
 * Class TableDocsMods
 * @package E10\Base
 */
class TableDocsMods extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.docsmodes', 'e10_base_docsmodes', 'Módy dokumentů');
	}
}


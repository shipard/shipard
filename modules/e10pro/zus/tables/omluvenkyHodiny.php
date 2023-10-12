<?php

namespace e10pro\zus;
use \Shipard\Table\DbTable;


/**
 * class TableOmluvenkyHodiny
 */
class TableOmluvenkyHodiny extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.omluvenkyHodiny', 'e10pro_zus_omluvenkyHodiny', 'Omluvené hodiny');
	}
}

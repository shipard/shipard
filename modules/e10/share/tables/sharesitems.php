<?php

namespace E10\Share;

use \E10\DbTable;


/**
 * Class TableSharesItems
 * @package E10\Share
 */
class TableSharesItems extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10.share.sharesitems', 'e10_share_sharesitems', 'Položky Sdílení');
	}
}


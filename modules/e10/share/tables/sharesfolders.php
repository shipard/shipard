<?php

namespace E10\Share;

use \E10\DbTable;


/**
 * Class TableSharesFolders
 * @package E10\Share
 */
class TableSharesFolders extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10.share.sharesfolders', 'e10_share_sharesfolders', 'Složky Sdílení');
	}
}


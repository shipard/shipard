<?php

namespace plans\core\libs;

/**
 * class ViewItemsGridTree
 */
class ViewItemsGridTree extends \plans\core\libs\ViewItemsGrid
{
	public function init ()
	{
		$this->useViewTree = 1;
		parent::init();
	}
}

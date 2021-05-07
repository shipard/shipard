<?php

namespace wkf\docs\viewers;


use \e10\utils;


/**
 * Class DashboardIssuesSectionsTree
 * @package wkf\core\viewers
 */
class DashboardDocumentsTree extends \wkf\docs\viewers\DashboardDocumentsCore
{
	public function init ()
	{
		$this->treeMode = 1;
		parent::init();
	}
}

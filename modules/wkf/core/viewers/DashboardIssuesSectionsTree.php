<?php

namespace wkf\core\viewers;

/**
 * Class DashboardIssuesSectionsTree
 * @package wkf\core\viewers
 */
class DashboardIssuesSectionsTree extends \wkf\core\viewers\DashboardIssuesSection
{
	public function init ()
	{
		$this->treeMode = 1;
		parent::init();
	}
}

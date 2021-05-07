<?php

namespace wkf\core\viewers;

use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;


/**
 * Class DashboardIssuesSearch
 * @package wkf\core\viewers
 */
class DashboardIssuesSearch extends \wkf\core\viewers\DashboardIssuesCore
{
	public function init ()
	{
		parent::init();

		$this->usePanelRight = 1;
		$this->enableDetailSearch = TRUE;

	}

	public function qrySection(&$q, $selectPart)
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';
		$qv = $this->queryValues ();

		if ($fts === '' && !count($qv))
		{
			array_push ($q, ' AND 1 = 0');
			return;
		}

		$allUsersSections = array_keys($this->usersSections['all']);
		if (count($allUsersSections))
			array_push ($q, ' AND issues.[section] IN %in', $allUsersSections);
		else
			array_push ($q, ' AND issues.[section] = %i', -1);
	}

	public function createTopMenuSearchCode ()
	{
		return $this->createCoreSearchCode('e10-sv-search-toolbar-fixed');
	}

	function createStaticContent()
	{
	}

	public function endMark ($blank)
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';
		if ($fts === '')
			return '';

		return parent::endMark($blank);
	}
}
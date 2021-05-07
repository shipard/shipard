<?php

namespace wkf\core\viewers;


use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;


/**
 * Class DashboardIssuesUser
 * @package wkf\core\viewers
 */
class DashboardIssuesUser extends \wkf\core\viewers\DashboardIssuesCore
{
	public function init ()
	{
		parent::init();

		$this->usePanelRight = FALSE;
		$this->panesColumns = 1;
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');
		$qry = [];
		$params = new \E10\Params ($panel->table->app());

		$this->createPanelContentRight_Tags($panel,$params, $qry);
//		$this->createPanelContentRight_UserRelated($panel,$params, $qry);

		if (count($qry))
			$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function qrySection(&$q, $selectPart)
	{
		$allUsersSections = array_keys($this->usersSections['all']);

		if (count($allUsersSections))
			array_push ($q, ' AND issues.[section] IN %in', $allUsersSections);
		else
			array_push ($q, ' AND issues.[section] = %i', -1);

		$ug = $this->app()->userGroups ();

		array_push($q, ' AND (');
		array_push ($q, 'EXISTS (SELECT ndx FROM [e10_base_doclinks] WHERE issues.ndx = srcRecId',
			' AND srcTableId = %s','wkf.core.issues', ' AND linkId = %s','wkf-issues-assigned',
			' AND dstTableId = %s', 'e10.persons.persons',
			' AND dstRecId = %i', $this->thisUserId, ')');

		if (count ($ug) !== 0)
		{
			array_push ($q, ' OR ');
			array_push ($q, 'EXISTS (SELECT ndx FROM [e10_base_doclinks] WHERE issues.ndx = srcRecId',
				' AND srcTableId = %s','wkf.core.issues', ' AND linkId = %s','wkf-issues-assigned',
				' AND dstTableId = %s', 'e10.persons.groups',
				' AND dstRecId IN %in', $ug, ')');
		}

		array_push($q, ')');
	}
}


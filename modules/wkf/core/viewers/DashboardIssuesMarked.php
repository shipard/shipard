<?php

namespace wkf\core\viewers;

use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;


/**
 * Class DashboardIssuesMarked
 * @package wkf\core\viewers
 */
class DashboardIssuesMarked extends \wkf\core\viewers\DashboardIssuesCore
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

		array_push($q, ' AND EXISTS (SELECT ndx FROM [wkf_base_docMarks] WHERE issues.ndx = rec',
			' AND [table] = %i', 1241, ' AND [mark] = %i', 101, ' AND [state] != %i', 0, ' AND [user] = %i', $this->thisUserId, ')');
		array_push($q, ' AND issues.[onTop] = %i', 0);
	}
}


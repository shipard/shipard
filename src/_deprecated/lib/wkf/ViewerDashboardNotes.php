<?php

namespace lib\wkf;


use \e10\utils, \e10\TableViewPanel, e10pro\wkf\TableMessages;


/**
 * Class ViewerDashboardBoard
 * @package lib\wkf
 */
class ViewerDashboardNotes extends \lib\wkf\ViewerDashboardCore
{
	public function init ()
	{
		$this->usePanelLeft = TRUE;
		$this->usePanelRight = 1;
		$this->hasProjectsFilter = TRUE;

		parent::init();
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		$qry = [];

		// -- projects
		$this->addProjectsToPanel ($panel, $qry);


		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function qryMessageTypes (&$q, $selectPart)
	{
		array_push ($q, ' AND (');

		array_push ($q, '(',
				'messages.[msgType] = %i', TableMessages::mtNote,
				' AND (messages.[docStateMain] <= 1)',
				')');

		array_push ($q, ')');
	}


	protected function qryOrder (&$q, $selectPart)
	{
		array_push ($q, ' ORDER BY [docStateMain], messages.subject, messages.ndx');
	}
}

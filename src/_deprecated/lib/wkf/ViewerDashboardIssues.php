<?php

namespace lib\wkf;

use \e10\TableViewPanel, \e10\utils, \E10Pro\Wkf\TableMessages;


/**
 * Class ViewDashboardIssues
 * @package lib\wkf
 */
class ViewerDashboardIssues extends \lib\wkf\ViewerDashboardCore
{
	public function init ()
	{
		$this->usePanelLeft = TRUE;
		$this->usePanelRight = 1;
		$this->hasProjectsFilter = TRUE;
		$this->msgTypes = [TableMessages::mtIssue, TableMessages::mtActivity, TableMessages::mtBBoard];
		$this->enableDetailSearch = TRUE;

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
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = 'active';

		array_push ($q, ' AND (');

		array_push ($q, '(', 'messages.[msgType] IN %in', $this->msgTypes);

		if ($this->activeProjectPartNdx !== FALSE && $this->activeProjectPartNdx > 0)
		{
			array_push($q, ' AND (messages.[docStateMain] >= 1)');
		}
		else
		{
			$fts = $this->fullTextSearch ();
			if ($fts !== '')
			{
				if ($mqId === 'active')
					array_push($q, ' AND (messages.[docStateMain] IN %in)', [1, 2, 5]);
				elseif ($mqId === 'done')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 2);
				elseif ($mqId === 'archive')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 5);
				elseif ($mqId === 'trash')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 4);
			}
			else
			{
				if ($mqId === 'active')
					array_push($q, ' AND (messages.[docStateMain] = %i OR messages.[docState] = 8000)', 1);
				elseif ($mqId === 'done')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 2);
				elseif ($mqId === 'archive')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 5);
				elseif ($mqId === 'trash')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 4);

			}

			if (!$this->app()->hasRole('pwuser') && $mqId === 'all')
				array_push($q, ' AND (messages.[docStateMain] != %i)', 4);
		}

		array_push ($q, ')');

		if ($mqId === 'active')
		{
			array_push($q, ' OR (',
				'messages.[msgType] IN %in', $this->msgTypes,
				' AND messages.author = %i', $this->thisUserId, ' AND messages.docStateMain = 0',
				')');
		}
		array_push ($q, ')');
	}

	protected function qryOrder (&$q, $selectPart)
	{
		//array_push ($q, ' ORDER BY [docStateMain], messages.dateTouch DESC');
		array_push ($q, ' ORDER BY [displayOrder]');
	}

	function checkViewerGroup (&$item)
	{
		if ($item ['msgType'] == TableMessages::mtBBoard)
		{
			$item['vgId'] = 'bboard';
			$this->withBody = TRUE;
			$this->simpleHeaders = FALSE;
			$this->addViewerGroup ('bboard', ['cntColumns' => 2, 'Xtitle' => ['text' => 'Nástěnka', 'icon' => 'icon-bell-o', 'class' => 'e10-widget-big-text e10-me']]);
		}
		else
		{
			$item['vgId'] = 'issues';
			$this->initPanesOptions();
			$this->addViewerGroup ('issues', ['title' => '']);
		}
	}

	function addViewerGroup ($groupId, $groupDef)
	{
		if (!isset($this->objectData['viewerGroups']))
			$this->objectData['viewerGroups'] = [];
		if (isset ($this->objectData['viewerGroups'][$groupId]))
			return;

		$this->objectData['viewerGroups'][$groupId] = [
			'code' => (isset($groupDef['title'])) ? $this->app()->ui()->composeTextLine($groupDef['title']) : '',
			'cntColumns' => (isset($groupDef['cntColumns'])) ? $groupDef['cntColumns'] : 0,
		];
	}
}

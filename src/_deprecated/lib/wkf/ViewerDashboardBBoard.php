<?php

namespace lib\wkf;


use \e10\utils, \e10\TableViewPanel, e10pro\wkf\TableMessages;


/**
 * Class ViewerDashboardBBoard
 * @package lib\wkf
 */
class ViewerDashboardBBoard extends \lib\wkf\ViewerDashboardCore
{
	var $dateTomorrow;
	var $dateNextWeek;

	var $viewerGroups = [
		'bboard' => ['cntColumns' => 2],
		'notified' => ['title' => ['text' => 'Nové zprávy a komentáře', 'icon' => 'icon-bell-o', 'class' => 'e10-widget-big-text e10-me']],
		'recent' => ['title' => ['text' => 'Nedávné', 'icon' => 'icon-clock-o', 'class' => 'e10-widget-big-text']],
		'concepts' => ['title' => ['text' => 'Rozpracováno', 'icon' => 'icon-keyboard-o', 'class' => 'e10-widget-big-text']]
	];

	public function init ()
	{
		$this->uiPlace = 'bboard';
		$this->usePanelRight = 1;
		$this->msgTypes = [TableMessages::mtInbox, TableMessages::mtIssue, TableMessages::mtActivity];
		parent::init();

		$this->dateTomorrow = new \DateTime('+2 days');
		$this->dateNextWeek = new \DateTime('+1 week');

		$this->selectParts = ['bboard', 'notified', 'recent', 'concepts'];
	}

	public function qryMessageTypes (&$q, $selectPart)
	{
		if ($selectPart === 'bboard')
		{
			array_push ($q, ' AND ');
			array_push ($q, '(',
				'messages.[msgType] = %i', TableMessages::mtBBoard,
				' AND (messages.[docStateMain] = 1)'
			);
			$this->qryForLinkedPersons ($q, 'e10pro-wkf-message-notify');
			array_push ($q, ')');
		}

		if ($selectPart === 'notified')
		{
			if (count($this->notifyPks))
			{
				array_push($q, ' AND (', 'messages.[ndx] IN %in', $this->notifyPks, ')');
			}
			else
			{
				array_push($q, ' AND 0');
			}
		}

		if ($selectPart === 'recent')
		{
			array_push ($q, ' AND (');
			$dateLimit = new \DateTime('7 day ago');

			array_push ($q, '(messages.dateTouch > %d)', $dateLimit);

			if (count($this->notifyPks))
				array_push ($q, 'AND (', 'messages.[ndx] NOT IN %in', $this->notifyPks, ')');

			array_push ($q, ' AND ');


			array_push ($q, ' (');
				array_push ($q, '(');
					array_push ($q, 'messages.[msgType] = %i', TableMessages::mtIssue);
					array_push ($q, ' OR ');
					array_push ($q, '(');
						array_push ($q, 'messages.[msgType] IN %in', [TableMessages::mtInbox, TableMessages::mtActivity]);
						$this->qryForLinkedPersons ($q, ['e10pro-wkf-message-to', 'e10pro-wkf-message-notify', 'e10pro-wkf-message-participant']);
					array_push ($q, ')');
				array_push ($q, ')');
				array_push ($q, ' AND messages.[docStateMain] IN %in', [1, 2, 5]);
			array_push ($q, ')');

			array_push ($q, ')');
		}

		if ($selectPart === 'concepts')
		{
			array_push ($q, 'AND messages.author = %i', $this->thisUserId, ' AND messages.docStateMain = 0');
		}
	}

	protected function qryOrder (&$q, $selectPart)
	{
		//array_push ($q, ' ORDER BY messages.dateTouch DESC');
		array_push ($q, ' ORDER BY messages.displayOrder');
	}

	protected function qryOrderAll (&$q)
	{
		//array_push ($q, ' ORDER BY selectPartOrder, dateTouch DESC');
		array_push ($q, ' ORDER BY selectPartOrder, displayOrder');
	}

	function checkViewerGroup (&$item)
	{
		$item['vgId'] = $item['selectPart'];

		$this->addViewerGroup($item['vgId'], $this->viewerGroups[$item['vgId']]);

		if ($item['selectPart'] === 'bboard')
		{
			$this->withBody = TRUE;
			$this->simpleHeaders = FALSE;
		} else
		{
			$this->initPanesOptions();
		}
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');

		$qry = [];

		$addButtons = [];

		$allProjectsGroups = $this->app->cfgItem('e10pro.wkf.projectsGroups');
		foreach ($allProjectsGroups as $pgNdx => $pgDef)
		{
			if (!isset($pgDef['addTaskOnBBoard']))
				continue;

			if (!isset($this->usersProjectsGroups[$pgNdx]) || !count($this->usersProjectsGroups[$pgNdx]))
				continue;

			$title = ['text' => isset ($pgDef['addTaskOnBBoardTitle']) ? $pgDef['addTaskOnBBoardTitle'] :$pgDef['sn'], 'class' => 'h2 block'];
			$addButtons[] = $title;

			foreach ($this->usersProjectsGroups[$pgNdx] as $projectNdx)
			{
				$prj = $this->usersProjects[$projectNdx];

				$addParams = '__project='.$projectNdx;

				$addButtons[] = [
					'text' => $prj['title'], 'icon' => 'icon-plus-square', 'action' => 'newform', 'data-viewer' => $this->vid,
					'data-table' => 'e10pro.wkf.messages',
					'data-addParams' => '__msgType='.TableMessages::mtIssue.'&'.$addParams, 'title' => 'Přidat úkol',
					'data-srcobjecttype' => 'viewer', 'class' => 'btn-block',
				];
			}
		}

		if ($this->app()->hasRole('pwuser'))
		{
			if (count($addButtons))
				$addButtons[] = ['code' => "<hr style='margin: 1ex;'/>"];

			$btnParams = $this->addButtonsParams();
			$this->tableMessages->addWorkflowButtons($addButtons, $btnParams);
		}

		$qry[] = ['style' => 'content','type' => 'line', 'line' => $addButtons, 'pane' => 'e10-pane-params'];


		$panel->addContent(['type' => 'query', 'query' => $qry]);
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

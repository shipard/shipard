<?php


namespace lib\wkf;

require_once __APP_DIR__ . '/e10-modules/e10pro/wkf/wkf.php';


use e10\ContentRenderer, e10\uiutils, \e10\widgetBoard, e10\utils, E10\Utility;


/**
 * Class PersonDashboard
 * @package lib\wkf
 */
class PersonDashboard extends Utility
{
	var $tableProjects;
	var $projectsGroup = FALSE;
	var $activeProject = FALSE;
	var $usersProjects;

	var $notifications = [];
	var $notificationKeys = [];

	var $issues = [];

	var $content = [];
	var $cards = [];


	public function init()
	{
		$this->tableProjects = $this->app->table ('e10pro.wkf.projects');
		//$this->projectsGroup = $this->queryParam('projectGroup');
		$this->usersProjects = $this->tableProjects->usersProjects($this->projectsGroup);
	}

	function loadIssues()
	{
		$q [] = 'SELECT messages.*, persons.fullName as authorFullName, projects.fullName as projectFullName,';
		array_push ($q, ' parts.id as projectPartId, parts.deadline as partDeadline, folders.shortName AS projectFolderName');
		array_push ($q, ' FROM [e10pro_wkf_messages] as messages');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON messages.author = persons.ndx');
		array_push ($q, ' LEFT JOIN e10pro_wkf_projects as projects ON messages.project = projects.ndx');
		array_push ($q, ' LEFT JOIN e10pro_wkf_projectsParts as parts ON messages.projectPart = parts.ndx');
		array_push ($q, ' LEFT JOIN e10pro_wkf_projectsFolders as folders ON messages.projectFolder = folders.ndx');
		array_push ($q, ' WHERE 1');


		array_push ($q, ' AND (');

		// -- notification
		array_push ($q, ' messages.ndx IN %in', $this->notificationKeys);

		// -- tasks
		array_push ($q, 'OR (',
				'messages.[type] = %s', 'issue',
				' AND (', 'messages.date IS NOT NULL', ')',
				' AND (messages.[docStateMain] <= 1)');
		//$this->qryForLinkedPersons ($q, 'e10pro-wkf-message-assigned');
		array_push ($q, ')');


		array_push ($q, ')');

		if ($this->activeProject !== FALSE)
			array_push ($q, ' AND messages.project = %i', $this->activeProject);
		elseif (count($this->usersProjects))
			array_push($q, ' AND (messages.project IN %in', array_keys($this->usersProjects), ' OR messages.project = 0)');
		else
			array_push ($q, ' AND messages.project = %i', 0);


		$rows = $this->db()->query ($q);
		//error_log ("####".\dibi::$sql);
		foreach ($rows as $r)
		{
			//error_log ("____!!___".json_encode($r));
			$issue = $r->toArray();
			if ($issue['type'] === 'issue')
			{
				$this->issues['tasks'][$r['ndx']] = ['issue' => $issue];
			}

			if (key_exists($issue['ndx'], $this->notifications))
			{
				$this->issues['notifications'][$issue['ndx']] = ['issue' => $issue];
			}
		}
	}

	protected function loadNotifications ()
	{
		$q [] = 'SELECT ntf.*, persons.fullName as personName from [e10_base_notifications] AS ntf';
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON ntf.personSrc = persons.ndx');
		array_push ($q, ' WHERE ntf.[personDest] = %i', $this->app->userNdx());
		array_push ($q, ' AND ntf.[state] = 0', ' AND tableId = %s', 'e10pro.wkf.messages');
		array_push ($q, ' ORDER BY [created] DESC, ntf.[ndx] DESC');
		$rows = $this->db()->query ($q);

		$pks = [];
		foreach ($rows as $r)
		{
			$this->notifications[] = $r->toArray();

			if ($r['recIdMain'])
				$this->notificationKeys[] = $r['recIdMain'];
			$this->notificationKeys[] = $r['recId'];
			//if (!$r['recIdMain'])
			//	$this->doneUnread[] = $r['recId'];
		}

	}


	function load()
	{
		$this->loadNotifications();
		$this->loadIssues();
	}

	public function setProject ($projectNdx)
	{

	}

	public function run ()
	{
		$this->init();
		$this->load();
		$this->createContent();
	}

	public function createContent()
	{
		// -- header
		/*
		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
		$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 12]);
		$this->addContent(['type' => 'line', 'line' => ['text' => 'pokus č. 1', 'class' => 'h1 e10-error']]);
		$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);
*/
		// -- cards
		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
		$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 6]);
		$this->createCardNotification();
		$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);


	}


	function createCardNotification ()
	{
		if (!count($this->notifications))
			return;
		$ntfTypeClasses = ['', 'e10-row-plus', 'e10-state-confirmed', 'e10-state-done', 'e10-error'];

		$listTitle = [['value' => [['text' => 'Novinky', 'icon' => 'icon-comments-o', 'class' => 'h2']]]];
		$list = ['rows' => [], 'title' => $listTitle, 'table' => 'e10pro.wkf.messages'];

		//$this->issues['notifications'][$issue['ndx']] = ['issue' => $issue];
		foreach ($this->notifications as $ntf)
		{
			$ndx = ($ntf['recIdMain']) ? $ntf['recIdMain'] : $ntf['recId'];
			if (!isset($this->issues['tasks'][$ndx]))
				continue;


//			error_log ("_______".json_encode($ntf));
			$item = $this->issues['tasks'][$ndx]['issue'];
			$row = [/*'docStateClass' => $ntfTypeClasses[$ntf['ntfType']],*/ 'ndx' => $ndx];
			$tt = [];
			$tt[] = ['text' => $ntf['ntfTypeName'], 'iiicon' => 'icon-keyboard-o', 'class' => 'pull-right e10-small e10-tag e10-ds-block '.$ntfTypeClasses[$ntf['ntfType']]];
			$tt[] = ['text' => $item['subject'], 'icon' => $ntf['icon'], 'class' => ''];
			//$tt[] = ['text' => utils::datef($ntf['created'], '%D, %T'), 'icon' => 'icon-keyboard-o', 'class' => 'e10-small break'];
			$tt[] = ['text' => $ntf['personName'], 'icon' => 'system/iconUser', 'class' => 'e10-small break'];

			if ($item['projectFullName'])
				$tt[] = ['icon' => 'icon-lightbulb-o', 'class' => 'label label-primary', 'text' => $item['projectFullName']];


			$row['title'] = $tt;
			$list['rows'][] = $row;
		}

		if (count($list['rows']))
			$this->addContent(['pane' => 'e10-pane', 'type' => 'list', 'list' => $list]);
	}

	public function createCode()
	{
		$cr = new ContentRenderer($this->app());
		$cr->content = $this->content;
		$c = $cr->createCode();

		return $c;
	}

	function addContent($cp)
	{
		$this->content[] = $cp;
	}
}

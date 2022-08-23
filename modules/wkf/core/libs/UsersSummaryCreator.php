<?php

namespace wkf\core\libs;
use e10\Utility, e10\utils, \e10\json;


/**
 * Class UsersSummaryCreator
 * @package wkf\core\libs
 */
class UsersSummaryCreator extends Utility
{
	var $versionId = 5;

	var $userNdx = 0;
	var $usersSections = NULL;
	var $usersNotifications = NULL;

	var $thisIsHosting = FALSE;

	/** @var \wkf\core\TableIssues */
	var $tableIssues = NULL;

	function setUser($userNdx)
	{
		if (!$this->tableIssues)
			$this->tableIssues = $this->app()->table ('wkf.core.issues');

		$this->userNdx = $userNdx;
		$usersGroups = $this->app()->authenticator->userGroups ($userNdx);

		/** @var  $tableSections \wkf\base\TableSections */
		$tableSections = $this->app->table ('wkf.base.sections');
		$this->usersSections = $tableSections->usersSections($userNdx, $usersGroups);

		// -- load notifications
		$this->usersNotifications = [];
		$q = 'SELECT * FROM e10_base_notifications WHERE state = 0 AND personDest = %i AND tableId = %s';
		$rows = $this->db()->query ($q, $userNdx, 'wkf.core.issues');
		foreach ($rows as $r)
		{
			$this->usersNotifications[$r['recIdMain']][] = $r->toArray();
		}
	}

	function addSection(&$data, $sectionNdx)
	{
		$section = $this->usersSections['all'][$sectionNdx];

		$sectionTitle = '';
		$sectionTitle .= $section['sn'];

		$data['sections'][$sectionNdx] = [
			'title' => $sectionTitle, 'icon' => $section['icon'],
			'cntUnread' => 0, 'cntTodo' => 0,
			'issues' => ['unread' => [], 'todo' => []]
		];

		if (isset($section['parentSection']) && $section['parentSection'])
		{
			$parentSection = isset($this->usersSections['all'][$section['parentSection']]) ? $this->usersSections['all'][$section['parentSection']] : ['sn' => '!'.$section['parentSection']];
			$data['sections'][$sectionNdx]['psTitle'] = $parentSection['sn'];
		}
	}

	function createUnread($userNdx, &$data)
	{
		$q[] = 'SELECT issues.section, sections.parentSection, COUNT(*) AS [cnt] ';
		array_push($q, ' FROM e10_base_notifications AS ntf');
		array_push($q, ' LEFT JOIN wkf_core_issues AS issues ON ntf.recIdMain = issues.ndx');
		array_push($q, ' LEFT JOIN wkf_base_sections AS sections ON issues.section = sections.ndx');
		array_push($q, ' LEFT JOIN wkf_base_sections AS topSections ON sections.parentSection = topSections.ndx');
		array_push($q, ' WHERE tableId = %s', 'wkf.core.issues', ' AND state = 0 AND ntf.personDest = %s', $userNdx);
		array_push($q, ' GROUP BY issues.section, sections.parentSection');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!isset($data['sections'][$r['section']]))
			{
				if ($r['section'] === NULL || !isset($this->usersSections['all'][$r['section']]))
					continue;
				$this->addSection($data, $r['section']);
			}

			$data['sections'][$r['section']]['cntUnread'] += $r['cnt'];
			$data['cntUnread'] += $r['cnt'];
		}
	}

	function createTodo($userNdx, &$data)
	{
		if (!count($this->usersSections['all']))
			return;

		$q[] = 'SELECT issues.section, COUNT(*) AS [cnt] ';
		array_push($q, ' FROM wkf_core_issues AS [issues]');
		array_push($q, ' WHERE section IN %in', array_keys($this->usersSections['all']));
		array_push($q, ' AND [docStateMain] = %i', 1);
		array_push($q, ' GROUP BY issues.section');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!isset($data['sections'][$r['section']]))
			{
				if ($r['section'] === NULL || !isset($this->usersSections['all'][$r['section']]))
					continue;
				$this->addSection($data, $r['section']);
			}

			$data['sections'][$r['section']]['cntTodo'] += $r['cnt'];
			$data['cntTodo'] += $r['cnt'];
		}
	}

	function createIssues($userNdx, &$data, $mode)
	{
		if (!count($this->usersSections['all']))
			return;
		if ($mode === 'unread' && !count($this->usersNotifications))
			return;

		$q [] = 'SELECT issues.ndx, issues.subject, issues.docState, issues.docStateMain, ';
		array_push ($q, ' issues.section, issues.issueKind, issues.issueType,');

		array_push ($q, ' persons.fullName AS authorFullName, ');
		array_push ($q, ' targets.shortName AS targetName,');
		array_push ($q, ' statuses.[order] AS statusOrder');
		array_push ($q, ' FROM [wkf_core_issues] AS issues');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON issues.author = persons.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_targets AS [targets] ON issues.target = targets.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_issuesStatuses AS [statuses] ON issues.status = statuses.ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND issues.[section] IN %in', array_keys($this->usersSections['all']));

		if ($mode === 'unread')
		{
			array_push($q, ' AND [issues].ndx IN %in', array_keys($this->usersNotifications));
		}
		elseif ($mode === 'todo')
		{
			if (count($this->usersNotifications))
				array_push($q, ' AND [issues].ndx NOT IN %in', array_keys($this->usersNotifications));

			array_push($q, ' AND (');
			array_push($q, 'issues.[docStateMain] = %i', 1);
			array_push($q,' OR (issues.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $userNdx], ')',
				' OR issues.[docState] = 8000');
			array_push($q, ')');

			// == marked or important or pin to top or assigned
			array_push($q, ' AND (');
			// -- important
			array_push($q, 'issues.[priority] < %i', 10);
			// -- pin to top
			array_push($q, ' OR issues.[onTop] >= %i', 5);
			// -- marked
			array_push ($q, ' OR EXISTS (SELECT ndx FROM [wkf_base_docMarks] WHERE issues.ndx = rec',
				' AND [table] = %i', 1241, ' AND [mark] = %i', 101, ' AND [state] != %i', 0, ' AND [user] = %i', $userNdx, ')');
			// -- assigned
			array_push ($q, ' OR EXISTS (SELECT ndx FROM [e10_base_doclinks] WHERE issues.ndx = srcRecId',
				' AND srcTableId = %s','wkf.core.issues', ' AND linkId = %s','wkf-issues-assigned',
				' AND dstTableId = %s', 'e10.persons.persons',
				' AND dstRecId = %i', $userNdx, ')');
			array_push($q, ')');
		}

		array_push ($q, ' ORDER BY [issues].[displayOrder]');

		array_push($q, 'LIMIT 120');


		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!isset($data['sections'][$r['section']]))
			{
				if ($r['section'] === NULL)
					continue;

				if (!isset($this->usersSections['all'][$r['section']]))
				{
					//echo " === ERROR: section {$r['section']} not found for user #{$userNdx}\n";
					//echo "   ".json_encode(array_keys($usersSections['all']))."\n\n";
					continue;
				}
				$this->addSection($data, $r['section']);
			}

			$docState = $this->tableIssues->getDocumentState ($r);
			$issueItem = [
				'ndx' => $r['ndx'], 'section' => $r['section'],
				'icon' => $this->tableIssues->tableIcon($r, 1),
				'subject' => $r['subject'],
				'dsc' => $this->tableIssues->getDocumentStateInfo ($docState ['states'], $r, 'styleClass'),
			];

			$data['issues'][$r['ndx']] = $issueItem;
			$data['sections'][$r['section']]['issues'][$mode][] = $r['ndx'];

			//echo " * ".json_encode($issueItem, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
		}
	}

	function createOneUser($userNdx)
	{
		$this->setUser($userNdx);

		$data = ['versionId' => $this->versionId, 'cntUnread' => 0, 'cntTodo' => 0, 'sections' => [], 'issues' => []];
		$this->createUnread($userNdx, $data);
		$this->createTodo($userNdx, $data);
		$this->createIssues($userNdx, $data, 'unread');
		$this->createIssues($userNdx, $data, 'todo');

		$exist = $this->db()->query('SELECT [ndx], [checksum] FROM [e10_base_usersSummary] WHERE [user] = %i',
			$userNdx, ' AND [summaryType] = %i', 0)->fetch();

		$dataStr = json::lint($data);
		$dataCheckSum = sha1($dataStr);

		if ($exist)
		{
			if ($exist['checksum'] !== $dataCheckSum)
			{
				$update = [
					'cnt' => $data['cntUnread'] + $data['cntTodo'], 'used' => 1,
					'checksum' => $dataCheckSum, 'data' => $dataStr,
					'updated' => new \DateTime(), 'sent' => 0,
				];
				$this->db()->query('UPDATE [e10_base_usersSummary] SET ', $update, ' WHERE ndx = %i', $exist['ndx']);
			}
			else
				$this->db()->query('UPDATE [e10_base_usersSummary] SET [used] = %i', 1, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$newItem = [
				'user' => $userNdx, 'summaryType' => 0,
				'cnt' => $data['cntUnread'] + $data['cntTodo'], 'used' => 1,
				'checksum' => $dataCheckSum, 'data' => $dataStr,
				'updated' => new \DateTime(), 'sent' => 0,
			];
			$this->db()->query('INSERT INTO [e10_base_usersSummary] ', $newItem);
		}
	}

	function createAllUsers()
	{
		$this->db()->query('UPDATE [e10_base_usersSummary] SET [used] = %i', 0);

		$q[] = 'SELECT ndx FROM [e10_persons_persons] ';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [roles] != %s', '', ' AND [roles] != %s', 'guest');
		array_push($q, ' AND [personType] = %i', 1);

		if ($this->thisIsHosting)
			array_push($q, ' AND [accountType] = %i', 1);
		else
			array_push($q, ' AND [accountType] = %i', 2);

		array_push($q, ' AND [docStateMain] <= %i', 2);
		array_push($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->createOneUser($r['ndx']);
		}

		$this->db()->query('DELETE FROM [e10_base_usersSummary] WHERE [used] != %i', 1);
	}

	function upload()
	{
		if ($this->app->cfgItem ('dsMode', 1) !== 0)
			return;

		$ce = NULL;

		$q[] = 'SELECT [us].*, [users].[loginHash], [users].[fullName] FROM [e10_base_usersSummary] AS [us]';
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [users] ON [us].[user] = [users].[ndx]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [us].[summaryType] = %i', 0);
		array_push($q, ' AND [us].[sent] = %i', 0);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$data = [
				'dsId' => $this->app()->cfgItem('dsid'),
				'loginHash' => $r['loginHash'],
				'data' => json_decode($r['data'], TRUE),
				'cnt' => $r['cnt'], 'checksum' => $r['checksum'],
			];

			//echo "- ".json_encode($data)."\n";

			if (!$ce)
			{
				$hostingCfg = utils::hostingCfg();
				$ce = new \lib\objects\ClientEngine($this->app());
				$ce->apiUrl = 'https://hq.shipard.app/' . 'api/objects/call/hosting-user-summary-upload';
				$ce->apiKey = $hostingCfg['hostingApiKey'];
			}

			$res = $ce->apiCall($ce->apiUrl, $data);
			if ($res && isset($res['success']) && $res['success'])
			{
				$this->db()->query('UPDATE [e10_base_usersSummary] SET [sent] = %i', 1, ' WHERE [ndx] = %i', $r['ndx']);
			}
			//echo "--> ".json_encode($res)." {$r['fullName']} \n";
		}
	}

	public function run()
	{
		if ($this->app->model()->module ('hosting.core') !== FALSE)
			$this->thisIsHosting = TRUE;

		$this->createAllUsers();
		$this->upload();
	}
}

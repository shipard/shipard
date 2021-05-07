<?php


namespace wkf\core\services;

use e10\Utility, e10\json, e10\utils, \e10\str;


/**
 * Class UpgradeToNewWorkflow
 * @package wkf\core\services
 */
class UpgradeToNewWorkflow extends Utility
{
	/** @var \e10\DbTable */
	var $tableMessages;
	var $ndx;
	var $recData;

	/** @var \e10\DbTable */
	var $tableProjects;
	var $projects = [];

	/** @var \wkf\core\TableIssues */
	var $tableIssues;
	var $systemSections;

	var $allIssuesKinds;
	var $allSections;
	var $allStatuses;

	var $oldMessagesKinds;

	var $lostIssuesLimit;

	/** @var \wkf\docs\TableDocuments */
	var $tableDocuments;


	function init()
	{
		$this->tableMessages = $this->app()->table ('e10pro.wkf.messages');
		$this->tableProjects = $this->app()->table ('e10pro.wkf.projects');

		$this->allIssuesKinds = $this->app()->cfgItem ('wkf.issues.kinds', []);
		$this->allSections = $this->app()->cfgItem ('wkf.sections.all', []);
		$this->allStatuses = $this->app()->cfgItem ('wkf.issues.statuses', []);

		$this->lostIssuesLimit = new \DateTime('2 month ago');

		$this->oldMessagesKinds = $this->app()->cfgItem ('e10pro.wkf.msgKinds', []);

		$this->tableIssues = $this->app()->table ('wkf.core.issues');

		// -- systemSections
		$this->systemSections = $this->app()->cfgItem ('wkf.systemSections.types', []);
		foreach ($this->systemSections as $ssNdx => $ss)
		{
			$systemSection = $this->app->db()->query('SELECT ndx FROM [wkf_base_sections] WHERE [systemSectionType] = %i', $ssNdx)->fetch();
			if ($systemSection)
				$this->systemSections[$ssNdx]['defaultSection'] = $systemSection['ndx'];
		}

		$this->tableDocuments = $this->app()->table ('wkf.docs.documents');
	}

	function upgradeIssues()
	{
		$q[] = 'SELECT messages.ndx';
		array_push($q, ' FROM e10pro_wkf_messages AS messages');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND msgType IN %in', [0, 1, 2]); // issues & inbox
		array_push($q, ' ORDER BY ndx');

		$counter = 1;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($counter % 100 === 0)
				echo '.';
			if ($counter % 500 === 0)
				echo sprintf('%7s', utils::nf($counter));
			if ($counter % 5000 === 0)
				echo "\n";

			if (!$this->doOneIssue($r['ndx']))
			{
				echo "\n --- FAILED --- \n";
				break;
			}

			$counter++;
			//if ($counter > 3000000)
			//	break;
		}
		if ($counter % 500 !== 0)
			echo str_repeat(' ', 1 + 5 - (round($counter/100, 0, PHP_ROUND_HALF_UP) - intval($counter/500)*5)).utils::nf($counter)."\n";

		echo "===== DONE =============================================================================================================\n";
	}

	function doOneIssue($issueNdx)
	{
		$this->ndx = $issueNdx;
		$this->recData = $this->tableMessages->loadItem ($issueNdx);

		//echo $this->recData['subject'].' --> ';

		$newIssue = [
			'ndx' => $this->ndx,
			'author' => $this->recData['author'], 'source' => $this->recData['source'],
			'priority' => $this->recData['priority'], 'onTop' => $this->recData['onTop'], 'disableComments' => $this->recData['disableComments'],
			'activateCnt' => $this->recData['activateCnt'], 'linkId' => $this->recData['linkId'],
			'displayOrder' => $this->recData['displayOrder'],
			'dateIncoming' => $this->recData['dateIncoming'], 'dateDeadline' => $this->recData['date'],
			'dateCreate' => $this->recData['dateCreate'], 'dateTouch' => $this->recData['dateTouch'],
			'subject' => $this->recData['subject'], 'text' => $this->recData['text'],
			'status' => 0,
			'docState' => $this->recData['docState'], 'docStateMain' => $this->recData['docStateMain'],
		];

		$newIssue['displayOrder'] = $this->tableIssues->displayOrder ($newIssue);

		$this->doOneIssueSectionNdx($newIssue);
		$this->doOneIssueKind($newIssue);
		//$this->doOneIssueStatus($newIssue);
		$this->doOneIssueProperties($newIssue);
		$this->doOneIssueIssueId($newIssue);

		// -- link to document
		if ($this->recData['tableid'] !== NULL && $this->recData['tableid'] !== '')
		{
			if ($this->recData['tableid'] === 'e10pro.wkf.messages')
				$this->recData['tableid'] = 'wkf.core.issues';
			if ($this->recData['tableid'] === 'e10pro.wkf.documents')
				$this->recData['tableid'] = 'wkf.docs.documents';

			$tableNdx = $this->app()->model()->tableNdx($this->recData['tableid']);
			if (!$tableNdx)
			{
				echo "\n ### ERROR: Invalid tableId `{$this->recData['tableid']}` [".json_encode($this->recData['tableid'])."]; #{$this->recData['ndx']}; {$this->recData['subject']}\n";
				//return FALSE;
			}
			if ($tableNdx && $this->recData['recid'])
			{
				$newIssue['tableNdx'] = $tableNdx;
				$newIssue['recNdx'] = $this->recData['recid'];
			}
		}

		// === INSERT & co. ===
		$this->db()->query('INSERT INTO [wkf_core_issues] ', $newIssue);
		$this->doOneIssueComments();

		// -- attachmnets
		$this->db()->query ('UPDATE [e10_attachments_files] SET tableid = %s', 'wkf.core.issues',
			' WHERE tableid = %s', 'e10pro.wkf.messages', ' AND recid = %i', $this->ndx);

		// -- classification/tags
		$this->db()->query ('UPDATE [e10_base_clsf] SET tableid = %s', 'wkf.core.issues', ', [group] = %s', 'wkfIssuesTags',
			' WHERE tableid = %s', 'e10pro.wkf.messages', ' AND [group] = %s', 'wkfMessagesTags',
			' AND recid = %i', $this->ndx);

		// -- docLinks / persons
		$this->db()->query ('UPDATE [e10_base_doclinks] SET srcTableId = %s', 'wkf.core.issues', ', [linkId] = %s', 'wkf-issues-assigned',
			' WHERE srcTableId = %s', 'e10pro.wkf.messages', ' AND [linkId] = %s', 'e10pro-wkf-message-assigned',
			' AND srcRecId = %i', $this->ndx);
		$this->db()->query ('UPDATE [e10_base_doclinks] SET srcTableId = %s', 'wkf.core.issues', ', [linkId] = %s', 'wkf-issues-from',
			' WHERE srcTableId = %s', 'e10pro.wkf.messages', ' AND [linkId] = %s', 'e10pro-wkf-message-from',
			' AND srcRecId = %i', $this->ndx);
		$this->db()->query ('UPDATE [e10_base_doclinks] SET srcTableId = %s', 'wkf.core.issues', ', [linkId] = %s', 'wkf-issues-to',
			' WHERE srcTableId = %s', 'e10pro.wkf.messages', ' AND [linkId] = %s', 'e10pro-wkf-message-to',
			' AND srcRecId = %i', $this->ndx);
		$this->db()->query ('UPDATE [e10_base_doclinks] SET srcTableId = %s', 'wkf.core.issues', ', [linkId] = %s', 'wkf-issues-notify',
			' WHERE srcTableId = %s', 'e10pro.wkf.messages', ' AND [linkId] = %s', 'e10pro-wkf-message-notify',
			' AND srcRecId = %i', $this->ndx);

		// -- docLinks / documents inbox
		$this->db()->query ('UPDATE [e10_base_doclinks] SET dstTableId = %s', 'wkf.core.issues', ', [linkId] = %s', 'e10docs-inbox',
			' WHERE dstTableId = %s', 'e10pro.wkf.messages', ' AND [linkId] = %s', 'e10doc-inbox',
			' AND dstRecId = %i', $this->ndx);

		/*
		if (isset($newIssue['systemInfo']))
		{
			echo $newIssue['systemInfo']."\n";
			return FALSE;
		}
		*/

		return TRUE;
	}

	function doOneIssueSectionNdx(&$newIssue)
	{
		$sectionNdx = 0;

		if ($this->recData['tableid'] === 'e10doc.core.heads')
		{
			$sectionNdx = $this->systemSections[51]['defaultSection']; // účtárna --> doklady
			$newIssue['section'] = $sectionNdx;
			return;
		}

		if ($this->recData['project'])
		{
			$projectInfo = $this->projectInfo($this->recData['project']);
			if ($projectInfo && $projectInfo['newWorkflowSection'])
				$sectionNdx = $projectInfo['newWorkflowSection'];
		}

		$oldMsgKind = isset($this->oldMessagesKinds[$this->recData['msgKind']]) ?
			$this->oldMessagesKinds[$this->recData['msgKind']] : [];

		//echo " - msgKind: !{$this->recData['msgKind']}! - ".json_encode($oldMsgKind)."\n";

		if (isset($oldMsgKind['nws']) && $oldMsgKind['nws'] && !$sectionNdx)
		{
			$sectionNdx = $oldMsgKind['nws'];
		}

		if (!$sectionNdx)
		{
			if ($newIssue['subject'] === 'Chybné účtování' && isset($this->systemSections[58]['defaultSection']))
				$sectionNdx = $this->systemSections[58]['defaultSection'];
			elseif (str::substr($newIssue['subject'], 0, 15) === 'Výkupní lístek ' && isset($this->systemSections[58]['defaultSection']))
				$sectionNdx = $this->systemSections[121]['defaultSection'];
			elseif (str::substr($newIssue['subject'], 0, 14) === 'Úhrada výkupu ' && isset($this->systemSections[58]['defaultSection']))
				$sectionNdx = $this->systemSections[121]['defaultSection'];
			elseif ($newIssue['subject'] === 'Kontrola uživatelských práv' && isset($this->systemSections[169]['defaultSection']))
				$sectionNdx = $this->systemSections[161]['defaultSection'];
			elseif (isset($this->systemSections[20]['defaultSection'])) // secretariat
				$sectionNdx = $this->systemSections[20]['defaultSection'];

			if (!$sectionNdx)
				$sectionNdx = 1;
		}

		$newIssue['section'] = $sectionNdx;
	}

	function doOneIssueKind(&$newIssue)
	{
		$issueType = 0;
		$issueKind = 0;

		$oldMsgKind = isset($this->oldMessagesKinds[$this->recData['msgKind']]) ?
			$this->oldMessagesKinds[$this->recData['msgKind']] : [];

		if (isset($oldMsgKind['nwk']) && $oldMsgKind['nwk'])
		{
			$issueKind = $oldMsgKind['nwk'];
			$issueType = $this->allIssuesKinds[$issueKind]['issueType'];
		}
		elseif ($this->recData['msgType'] == 1)
		{ // -- inbox
			$defaultInboxType = \e10\searchArray($this->allIssuesKinds, 'issueType', 1);
			if ($defaultInboxType)
			{
				$issueType = 1;
				$issueKind = $defaultInboxType['ndx'];
			}
		}
		elseif ($this->recData['msgType'] == 0)
		{ // -- issue
			// -- detect from section issues kinds
			$s = $this->allSections[$newIssue['section']];
			if ($s['parentSection'])
				$s = $this->allSections[$s['parentSection']];

			if (isset($s['issuesKinds']) && count($s['issuesKinds']))
			{
				$issueKind = $s['issuesKinds'][0]['ndx'];
				$issueType = $this->allIssuesKinds[$issueKind]['issueType'];
			}

			if (!$issueKind)
			{
				$defaultIssueType = \e10\searchArray($this->allIssuesKinds, 'issueType', 0);
				if ($defaultIssueType)
				{
					$issueType = 0;
					$issueKind = $defaultIssueType['ndx'];
				}
			}
		}

		if (!$issueKind && $this->recData['msgType'] == 2 && str::substr($newIssue['subject'], 0, 15) === 'Výkupní lístek ')
		{ // sent purchase
			$defaultIssueKind = \e10\searchArray($this->allIssuesKinds, 'systemKind', 125);
			$issueType = 3;
			$issueKind = $defaultIssueKind['ndx'];
		}
		elseif (!$issueKind && $this->recData['msgType'] == 2)
		{ // unknown outbox message
			$defaultIssueKind = \e10\searchArray($this->allIssuesKinds, 'systemKind', 4);
			$issueType = 3;
			$issueKind = $defaultIssueKind['ndx'];
		}

		if (!$issueKind)
		{
			echo "---ERROR: unknown issueKind ---".json::lint($this->recData)."\n\n";
			return FALSE;
		}

		$newIssue['issueType'] = $issueType;
		$newIssue['issueKind'] = $issueKind;
	}

	function doOneIssueStatus(&$newIssue)
	{
		if (!isset($this->allSections[$newIssue['section']]))
			return;

		$s = $this->allSections[$newIssue['section']];
		if ($s['parentSection'])
			$s = $this->allSections[$s['parentSection']];

		if (!$this->allStatuses['section'][$s['ndx']])
			return;
		$sectionStatuses = $this->allStatuses['section'][$s['ndx']];
		if (!count($sectionStatuses))
			return;

		foreach ($sectionStatuses as $ss)
		{
			$sectionStatus = $this->allStatuses['all'][$ss];

			if ($newIssue['docState'] == 1000 && $sectionStatus['lc'] === 0)
				$newIssue['status'] = $sectionStatus['ndx'];
			elseif ($newIssue['docState'] == 1200 && $sectionStatus['lc'] === 10)
				$newIssue['status'] = $sectionStatus['ndx'];
			elseif ($newIssue['docState'] == 4000 && $sectionStatus['lc'] === 60)
				$newIssue['status'] = $sectionStatus['ndx'];
			elseif ($newIssue['docState'] == 8000 && $sectionStatus['lc'] === 60)
				$newIssue['status'] = $sectionStatus['ndx'];
			elseif ($newIssue['docState'] == 9000 && $sectionStatus['lc'] === 90)
				$newIssue['status'] = $sectionStatus['ndx'];
			elseif ($newIssue['docState'] == 9000 && $sectionStatus['lc'] === 20)
				$newIssue['status'] = $sectionStatus['ndx'];
			elseif ($newIssue['docState'] == 9800 && $sectionStatus['lc'] === 20)
				$newIssue['status'] = $sectionStatus['ndx'];
			elseif ($newIssue['docState'] == 9800 && $sectionStatus['lc'] === 90)
				$newIssue['status'] = $sectionStatus['ndx'];

			if ($newIssue['status'] && $sectionStatus['lc'] < 60)
			{
				if ($newIssue['dateTouch'] && $newIssue['dateTouch'] < $this->lostIssuesLimit)
				{
					$lostStatus = $this->lostSectionStatus($s['ndx']);
					if ($lostStatus)
						$newIssue['status'] = $lostStatus;
				}
			}

			if ($newIssue['status'])
				break;
		}
	}

	function doOneIssueComments()
	{
		$q[] = 'SELECT messages.*';
		array_push($q, ' FROM e10pro_wkf_messages AS messages');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ownerMsg = %i', $this->ndx);
		array_push($q, ' AND msgType = %i', 4); // comment
		array_push($q, ' ORDER BY ndx');


		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newComment = [
				'ndx' => $r['ndx'], 'issue' => $this->ndx,
				'text' => $r['text'], 'author' => $r['author'],
				'dateCreate' => $r['dateCreate'], 'dateTouch' => $r['dateTouch'],
				'displayOrder' => $r['displayOrder'], 'activateCnt' => $r['activateCnt'],
				'docState' => $r['docState'], 'docStateMain' => $r['docStateMain'],
			];
			$this->db()->query('INSERT INTO [wkf_core_comments] ', $newComment);

			$this->db()->query ('UPDATE [e10_attachments_files] SET tableid = %s', 'wkf.core.comments',
				' WHERE tableid = %s', 'e10pro.wkf.messages', ' AND recid = %i', $r['ndx']);
		}
	}

	function doOneIssueProperties(&$newIssue)
	{
		$properties = \E10\Base\getPropertiesTable ($this->app(), 'e10pro.wkf.messages', $this->ndx);
		if (!count($properties))
			return;

		$systemInfo = [];

		if (isset ($properties['emailheaders']))
		{
			foreach ($properties['emailheaders'] as $key => $values)
			{
				foreach ($values as $value)
				{
					if ($key === 'eml-from')
					{
						$siItem = ['address' => $value['value']];
						if (isset($value['note']))
							$siItem['name'] = $value['note'];
						$systemInfo['email']['from'][] = $siItem;
					}
					elseif ($key === 'eml-to')
					{
						$siItem = ['address' => $value['value']];
						if (isset($value['note']))
							$siItem['name'] = $value['note'];
						$systemInfo['email']['to'][] = $siItem;
					}
					elseif ($key === 'eml-message-id')
						$systemInfo['email']['headers'][] = ['header' => 'message-id', 'value' =>$value['value']];
					elseif ($key === 'eml-in-reply-to')
						$systemInfo['email']['headers'][] = ['header' => 'in-reply-to', 'value' =>$value['value']];
					else
					{
						echo "\n --- UNKNOWN PROP [emailheaders] KEY `$key`: ".json_encode($value)."\n";
					}
				}
			}
		}

		if (isset ($properties['outboxsent']))
		{
			foreach ($properties['outboxsent'] as $key => $values)
			{
				foreach ($values as $value)
				{
					if ($key === 'outbox-email-to')
					{
						$siItem = ['address' => $value['value']];
						if (isset($value['note']))
							$siItem['name'] = $value['note'];
						$systemInfo['email']['to'][] = $siItem;
					}
					elseif ($key === 'outbox-email-id')
						$systemInfo['email']['headers'][] = ['header' => 'message-id', 'value' =>$value['value']];
					elseif ($key === 'outbox-printed')
					{
						$systemInfo['printed']['status'] = 1;
					}
					else
					{
						echo "\n --- UNKNOWN PROP [outboxsent] KEY `$key`: ".json_encode($value)."\n";
					}
				}
			}
		}

		if (isset ($properties['wfinfo']))
		{
			foreach ($properties['wfinfo'] as $key => $values)
			{
				foreach ($values as $value)
				{
					if ($key === 'wf-from')
					{
						$siItem = ['address' => $value['value']];
						if (isset($value['note']))
							$siItem['name'] = $value['note'];
						$systemInfo['webForm']['from'] = $siItem;
					}
					elseif ($key === 'wf-ipaddr')
					{
						$systemInfo['webForm']['srcIPAddress'] = $value['value'];
					}
					else
					{
						echo "\n --- UNKNOWN PROP [wfinfo] KEY `$key`: ".json_encode($value)."\n";
					}
				}
			}
		}

		foreach ($properties as $propGroupId => $propGroupContent)
		{
			if ($propGroupId === 'emailheaders' || $propGroupId === 'wfinfo' || $propGroupId == 'outboxsent')
				continue;

			echo "\n --- UNKNOWN PROP GROUP `$propGroupId`: ".json_encode($propGroupContent)."\n";
		}

		if (count($systemInfo))
			$newIssue['systemInfo'] = json::lint($systemInfo);

		//echo "####### ".$newIssue['systemInfo']."\n";
	}

	function lostSectionStatus($sectionNdx)
	{
		$sectionStatuses = $this->allStatuses['section'][$sectionNdx];
		foreach ($sectionStatuses as $ss)
		{
			$sectionStatus = $this->allStatuses['all'][$ss];
			if ($sectionStatus['lc'] === 40)
				return $ss;
		}

		return 0;
	}

	function doOneIssueIssueId(&$newIssue)
	{
		$iid = str_replace(' ', '.', utils::nf(100000+$newIssue['ndx']));

		$newIssue['issueId'] = $iid;
	}

	function upgradeClsf()
	{
		$this->db()->query ('UPDATE [e10_base_clsfitems] SET [group] = %s', 'wkfIssuesTags', ' WHERE [group] = %s', 'wkfMessagesTags');
	}

	public function run()
	{
		echo "===== UPGRADE TO NEW WORKFLOW ==========================================================================================\n";

		$this->init();
		$this->upgradeClsf();
		$this->clearNewTables();

		$this->upgradeIssues();
	}

	function clearNewTables()
	{
		$this->db()->query ('DELETE FROM [wkf_core_comments]');
		$this->db()->query ('DELETE FROM [wkf_core_issues]');
	}

	function projectInfo($projectNdx)
	{
		if (!isset($this->projects[$projectNdx]))
		{
			$this->projects[$projectNdx] = $this->tableProjects->loadItem($projectNdx);
		}

		return $this->projects[$projectNdx];
	}

	public function runDocuments()
	{
		echo "===== UPGRADE TO NEW DOCUMENTS =========================================================================================\n";

		$this->init();
		$this->clearNewTablesDocuments();
		$this->upgradeDocuments();
	}

	function clearNewTablesDocuments()
	{
		$this->db()->query ('DELETE FROM [wkf_docs_documents]');
	}

	function upgradeDocuments()
	{
		$q[] = 'SELECT docs.*';
		array_push($q, ' FROM e10pro_wkf_documents AS docs');
		array_push($q, ' WHERE 1');
		array_push($q, ' ORDER BY ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$folderNdx = 1;
			$documentKind = 1;

			$newItem = [
				'ndx' => $r['ndx'], 'title' => $r['title'],
				'text' => $r['text'], 'author' => $r['author'],
				'folder' => $folderNdx,
				'documentKind' => $documentKind,
				'dateCreate' => $r['dateCreate'],
				'dateTouch' => $r['dateTouch'],
				'activateCnt' => $r['activateCnt'],
				'docState' => $r['docState'],
				'docStateMain' => $r['docStateMain'],
				'documentId' => str_replace(' ', '.', utils::nf(10000+$r['ndx'])),
			];

			$newNdx = $this->tableDocuments->dbInsertRec($newItem);


			// -- attachmnets
			$this->db()->query ('UPDATE [e10_attachments_files] SET tableid = %s', 'wkf.docs.documents',
				' WHERE tableid = %s', 'e10pro.wkf.documents', ' AND recid = %i', $newNdx);

			// -- classification/tags
			$this->db()->query ('UPDATE [e10_base_clsf] SET tableid = %s', 'wkf.docs.documents', ', [group] = %s', 'wkfDocsTags',
				' WHERE tableid = %s', 'e10pro.wkf.documents', ' AND [group] = %s', 'wkfDocumentsTags',
				' AND recid = %i', $newNdx);

			// -- docLinks / persons
			$this->db()->query ('UPDATE [e10_base_doclinks] SET srcTableId = %s', 'wkf.docs.documents', ', [linkId] = %s', 'wkf-docs-participants',
				' WHERE srcTableId = %s', 'e10pro.wkf.documents', ' AND [linkId] = %s', 'e10pro-wkf-document-participant',
				' AND srcRecId = %i', $newNdx);
		}
	}
}

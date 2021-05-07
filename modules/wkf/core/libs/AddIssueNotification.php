<?php


namespace wkf\core\libs;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use e10\Utility, e10\utils, wkf\core\TableIssues, \lib\persons\LinkedPersons, \e10\str, \e10\json;


/**
 * Class AddIssueNotification
 * @package wkf\core\libs
 */
class AddIssueNotification extends Utility
{
	var $issueRecData = NULL;
	var $commentRecData = NULL;
	var $reason = 0;

	/** @var TableIssues */
	var $tableIssues;

	/** @var LinkedPersons */
	var $linkedPersons = NULL;


	function init ()
	{
		$this->tableIssues = $this->app()->table('wkf.core.issues');
	}

	public function setIssue($issueRecData, $reason = 0, $commentRecData = NULL)
	{
		$this->init();

		$this->issueRecData = $issueRecData;
		$this->commentRecData = $commentRecData;
		$this->reason = $reason;

		if (!$this->issueRecData && $this->commentRecData)
			$this->issueRecData = $this->tableIssues->loadItem($this->commentRecData['issue']);
	}

	public function createUsersNotifications()
	{
		$linkedPersonsRecId = $this->issueRecData['ndx'];
		$docStates = $this->tableIssues->documentStates ($this->issueRecData);

		$notify = 1;
		$ntfType = 99;

		$author = $this->issueRecData['author'];
		$subject = '';
		$recId = $this->issueRecData['ndx'];
		$recIdMain = $this->issueRecData['ndx'];

		if ($this->reason === 0)
		{ // issue
			if ($this->issueRecData['issueType'] == TableIssues::mtInbox && $this->issueRecData ['docState'] === 1000 && $this->issueRecData ['source'] === TableIssues::msHuman)
				$notify = 0; // new inbox messages only via email

			if ($this->issueRecData ['docStateMain'] === 0 && $this->issueRecData ['docState'] === 8000)
				$notify = 0; // edits is not notified
			elseif ($this->issueRecData['activateCnt'] > 1 && $this->issueRecData ['docStateMain'] === 1)
				$notify = 0; // only first 'publish' is notified

			$ntfType = $this->issueRecData ['docStateMain'];
		}
		elseif ($this->reason === 1)
		{ // comment
			$author = $this->commentRecData['author'];
			$recId = $this->commentRecData['ndx'];
			$recIdMain = $this->issueRecData['ndx'];
			$subject = $this->issueRecData['subject'];
			$ntfType = 92; // comment
		}

		if ($this->issueRecData['source'] === TableIssues::msAlert || $this->issueRecData['source'] === TableIssues::msTest)
			$ntfType = 90; // error
		elseif ($this->issueRecData['source'] === TableIssues::msEmail && $this->issueRecData ['docStateMain'] === 0)
			$ntfType = 91; // new email

		if (!$notify)
			return;

		if ($subject === '')
		{
			if ($this->issueRecData['subject'] !== '')
				$subject = $this->issueRecData['subject'];
			else
				$subject = $this->tableIssues->getDocumentStateInfo($docStates, $this->issueRecData, 'logName');
		}

		$n = [
			'tableId' => 'wkf.core.issues', 'recId' => $recId, 'recIdMain' => $recIdMain,
			'objectType' => 'document',
			'personSrc' => $author, 'subject' => str::upToLen($subject, 80),
			'icon' => $this->tableIssues->tableIcon($this->issueRecData), 'ntfType' => $ntfType, 'created' => new \DateTime()
		];

		$notifyTypeText = $this->tableIssues->getDocumentStateInfo ($docStates, $this->issueRecData, 'logName');
		if ($notifyTypeText !== FALSE)
			$n['ntfTypeName'] = $notifyTypeText;

		$this->addUsersNotifications ($n);
	}

	public function addUsersNotifications ($notification)
	{
		if (utils::$todayClass)
			return;

		$persons = [];
		$this->loadPersonsToNotify($persons);

		// -- set old notifications for this message as read
		$this->db()->query ('UPDATE [e10_base_notifications] SET [state] = 1 WHERE [tableId] = %s AND [recId] = %i', 'wkf.core.issues', $this->issueRecData['ndx']);

		// -- add new
		$thisUserNdx = $this->app()->userNdx();
		foreach ($persons as $dstUserNdx => $notificationState)
		{
			if ($dstUserNdx === $thisUserNdx)
				continue;
			if (!$notificationState)
				continue;
			$notification['personDest'] = $dstUserNdx;
			$this->db()->query ('INSERT INTO [e10_base_notifications]', $notification);
		}
	}

	function loadPersonsToNotify(&$persons)
	{
		$this->loadPersonsToNotify_thisIssue ($persons);
		$this->loadPersonsToNotify_Section($this->issueRecData['section'],$persons);
	}

	public function loadPersonsToNotify_thisIssue (&$persons)
	{
		$q[] = 'SELECT [user], [state] FROM [wkf_base_docMarks]';
		array_push($q, ' WHERE [mark] = %i', 101, ' AND [state] != %i', 0);
		array_push($q, ' AND [table] = %i', 1241 /* issues */, ' AND [rec] = %i', $this->issueRecData['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$persons[$r['user']] = $r['state'];
		}
	}

	public function loadPersonsToNotify_Section ($sectionNdx, &$persons)
	{
		$issueNdx = $this->issueRecData['ndx'];

		$q[] = 'SELECT [user], [state] FROM [wkf_base_docMarks]';
		array_push($q,' WHERE [mark] = %i', 100);
		array_push($q,' AND [state] != %i', 0);
		array_push($q,' AND [table] = %i', 1246, ' AND [rec] = %i', $sectionNdx);

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$p =$r['user'];
			$s = $r['state'];

			if ($r['state'] === 3)
			{ // -- important only
				if ($this->issueRecData['priority'] >= 10)
					$s = 0;
			}
			elseif ($r['state'] === 4)
			{ // -- me only
				if (!$this->linkedPersons)
				{
					$this->linkedPersons = new LinkedPersons($this->app());
					$this->linkedPersons->setSource('wkf.core.issues', $issueNdx);
					$this->linkedPersons->setFlags(LinkedPersons::lpfNicknames);
					$this->linkedPersons->load();
				}

				if (!in_array($r['user'], $this->linkedPersons->personsNdxs))
					$s = 0;
			}

			if (!$s)
				continue;

			if (isset($persons[$p]))
				continue;
			$persons[$p] = $s;
		}
	}

	function createExternalNotifications()
	{
		$q[] = 'SELECT extNtf.* FROM wkf_base_extNotifications AS extNtf';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' AND [minPriority] >= %i', $this->issueRecData['priority']);
		array_push($q, ' AND EXISTS (SELECT ndx FROM [e10_base_doclinks] WHERE extNtf.ndx = srcRecId',
			' AND srcTableId = %s','wkf.base.extNotifications', ' AND linkId = %s', 'wkf-ext-ntf-sections',
			' AND dstTableId = %s', 'wkf.base.sections',
			' AND dstRecId = %i', $this->issueRecData['section'], ')');

		$payload = [
			'subject' => $this->issueRecData['subject'],
			'bodyTextPlain' => '',
		];

		$enc = new \integrations\ntf\libs\ExtNotificationContent($this->app());
		if ($this->issueRecData['source'] === TableIssues::msAlert)
		{
			if ($this->commentRecData)
				$alertData = json_decode($this->commentRecData['text'], TRUE);
			else
				$alertData = json_decode($this->issueRecData['text'], TRUE);
			if ($alertData)
			{
				$enc->setContent($alertData['extNtfContent']);

				if (isset($alertData['extNtfContent']['msgTextPlain']))
					$payload['subject'] = str::upToLen($alertData['extNtfContent']['msgTextPlain'], 200);
				if (isset($alertData['extNtfContent']['msgTextPlain']))
					$payload['bodyTextPlain'] = $alertData['extNtfContent']['msgTextPlain'];
			}
		}
		else
		{
			if ($this->commentRecData)
			{
				$payload['bodyTextPlain'] = $this->commentRecData['text'];
				$enc->setText($this->commentRecData['text']);
			}
			else
			{
				$enc->setTitle($this->issueRecData['subject']);
				if ($this->issueRecData['source'] === TableIssues::msHuman)
				{
					$payload['bodyTextPlain'] = $this->issueRecData['subject'] . " \n" . $this->issueRecData['text'];
					$enc->setText($this->issueRecData['text']);
				}
				elseif ($this->issueRecData['source'] === TableIssues::msEmail)
				{
					$payload['bodyTextPlain'] = $this->issueRecData['subject'];
				}
			}
		}

		$enc->createCode();
		$payload['bodyTextHtml'] = $enc->code;

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newNtf = [
				'subject' => str::upToLen($payload['subject'], 200),
				'channel' => $r['l1Channel'], 'ntfSource' => 0, 'payload' => json::lint($payload),
				'sourceTableNdx' => 1241, 'sourceRecNdx' => $this->issueRecData['section'],
				'sourceCfgNdx' => $r['ndx'], 'doDelivery' => 1,
				'levelCurrent' => 0, 'levelMax' => 1,
				'repeatCurrent' => 0, 'repeatMax' => 1,
				'dtCreated' => new \DateTime(), 'dtDelivery' => NULL, 'dtNextTry' => new \DateTime(),
			];
			$this->db()->query('INSERT INTO [integrations_ntf_delivery] ', $newNtf);
			$newNdx = intval ($this->db()->getInsertId ());
			file_put_contents(__APP_DIR__.'/tmp/integration-ext-ntf-delivery', strval($newNdx));
		}
	}

	public function run()
	{
		if ($this->issueRecData['docState'] == 1000)
			return; // concept

		$this->createUsersNotifications();
		$this->createExternalNotifications();
	}
}

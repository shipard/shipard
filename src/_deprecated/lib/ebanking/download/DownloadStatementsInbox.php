<?php

namespace lib\ebanking\download;

use e10\utils, e10\Utility, e10pro\wkf\TableMessages, wkf\core\TableIssues;


/**
 * Class DownloadStatementsInbox
 * @package lib\ebanking\download
 */
class DownloadStatementsInbox extends \lib\ebanking\download\DownloadStatements
{
	protected $inboxQueryParams = [];

	protected function searchInboxMessage ()
	{
		$q[] = 'SELECT * FROM [wkf_core_issues] AS issues';
		array_push($q, ' WHERE [issueType] = %i', TableIssues::mtInbox);
		array_push($q, ' AND [docState] IN %in', [1000, 1001]);

		if (isset($this->inboxQueryParams['subject']))
			array_push($q, ' AND [subject] LIKE %s', $this->inboxQueryParams['subject'].'%');

		if (isset($this->inboxQueryParams['emailFrom']))
		{
			array_push($q,' AND systemInfo LIKE %s', '%"'.$this->inboxQueryParams['emailFrom'].'"%');
		}

		$rows = $this->db()->query($q);
		foreach ($rows as $row)
		{
			$this->doOneInbox($row);
		}
	}

	protected function doOneInbox ($recData)
	{
		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app->table ('wkf.core.issues');

		$this->inboxNdx = $recData['ndx'];

		$attachments = \E10\Base\getAttachments ($this->app, 'wkf.core.issues', $this->inboxNdx);
		foreach ($attachments as $a)
		{
			if (mb_substr($a, - mb_strlen($this->inboxQueryParams['attachmentSuffix'])) === $this->inboxQueryParams['attachmentSuffix'])
			{
				$fullFileName = __APP_DIR__.'/att/'.$a;
				$data = file_get_contents($fullFileName);
				if ($data === FALSE)
					continue;
				$this->statementTextData = $data;
				break;
			}
		}

		if ($this->statementTextData === FALSE)
			return;

		$this->saveToInbox_addNotify();
		$this->createBankDocument ();

		$issueKindNdx = $tableIssues->defaultSystemKind(52); // bank statement
		$sectionNdx = $tableIssues->defaultSection(54); // bank
		if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(51); // documents
		if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(20); // secretariat

		$this->updateInbox(['issueKind' => $issueKindNdx, 'section' => $sectionNdx, 'docState' => 1200, 'docStateMain' => 1]);
	}

	public function run ()
	{
		if (!$this->downloadEnabled)
			return;

		$this->searchInboxMessage();
	}
}

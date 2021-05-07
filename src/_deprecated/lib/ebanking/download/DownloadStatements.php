<?php

namespace lib\ebanking\download;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/bank/bank.php';


use e10\utils, e10\Utility, e10pro\wkf\TableMessages, wkf\core\TableIssues;


/**
 * Class DownloadStatements
 * @package lib\ebanking\download
 */
class DownloadStatements extends Utility
{
	protected $bankAccountCfg;
	protected $bankAccountRec;
	protected $bankAccountNdx;
	protected $bankAccountLinkedPersons;

	protected $latestStatementRec = FALSE;
	protected $latestStatementNumber = FALSE;

	protected $today;
	protected $downloadEnabled = FALSE;
	protected $nextStatementNumber = FALSE;
	protected $nextStatementYear = FALSE;

	protected $inboxRecData = [];
	protected $inboxAttachments = [];
	protected $inboxNdx = 0;

	protected $statementTextData = FALSE;

	public function init ()
	{
		$this->today = utils::today ();
		$this->checkLatestStatement ();
	}

	public function setBankAccount ($bankAccountCfg)
	{
		$this->bankAccountCfg = $bankAccountCfg;
		$this->bankAccountNdx = intval ($this->bankAccountCfg['ndx']);
		$this->bankAccountRec = $this->app->loadItem($this->bankAccountCfg['ndx'], 'e10doc.base.bankaccounts');
		$this->bankAccountLinkedPersons = \E10\Base\getDocLinks ($this->app, 'e10doc.base.bankaccounts', $this->bankAccountNdx);
	}

	protected function checkLatestStatement ()
	{
		$thisYearToday = intval($this->today->format('Y'));

		$useDateLimit = FALSE;
		$beginYearLimit = 0;
		if (isset($this->bankAccountCfg['useDownloadStatementBegin']))
		{
			$beginDateLimit = utils::createDateTime($this->bankAccountCfg['downloadStatementBeginDate']);
			if (!utils::dateIsBlank($beginDateLimit))
			{
				$beginYearLimit = intval($beginDateLimit->format('Y'));
				if ($beginYearLimit && $beginYearLimit <= $thisYearToday)
					$useDateLimit = TRUE;
			}
		}

		$q[] = 'SELECT * FROM [e10doc_core_heads]';
		array_push ($q, ' WHERE [docType] = %s', 'bank', ' AND [myBankAccount] = %i', $this->bankAccountNdx);
		array_push ($q, ' AND [docState] NOT IN %in', [4100, 9800]);

		if ($useDateLimit)
			array_push ($q, ' AND [datePeriodEnd] >= %d', $this->bankAccountCfg['downloadStatementBeginDate']);

		array_push ($q, ' ORDER BY [datePeriodEnd] DESC, docOrderNumber DESC');
		array_push ($q, ' LIMIT 1');

		$row = $this->db()->query ($q)->fetch ();
		if ($row)
		{
			$this->latestStatementRec = $row->toArray();
			$this->latestStatementNumber = $this->latestStatementRec['docOrderNumber'];

			if ($this->latestStatementRec['datePeriodEnd'] < $this->today) {
				$this->downloadEnabled = TRUE;
				$this->nextStatementNumber = $this->latestStatementNumber + 1;
				$this->nextStatementYear = intval($this->latestStatementRec['datePeriodEnd']->format('Y'));
			}
		}
		else
		{ // download first bank statement
			$this->downloadEnabled = TRUE;

			if ($useDateLimit)
			{
				$this->nextStatementNumber = $this->bankAccountCfg['downloadStatementBeginNumber'];
				$this->nextStatementYear = $beginYearLimit;
			}
			else
			{
				$this->nextStatementNumber = 1;
				$this->nextStatementYear = intval($this->today->format('Y'));
			}
		}
	}

	protected function addInboxAttachment ($fileName)
	{
		$this->inboxAttachments[] = $fileName;
	}

	protected function saveToInbox ()
	{
		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app->table('wkf.core.issues');

		$sectionNdx = 0;
		$issueKindNdx = 0;

		//if (!$issueKindNdx)
		$issueKindNdx = $tableIssues->defaultSystemKind(52); // bank statement
		//if (!$sectionNdx)
		$sectionNdx = $tableIssues->defaultSection(54); // bank
		if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(51); // documents
		if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(20); // secretariat

		$issueRecData = [
			'subject' => isset($this->inboxRecData['subject']) ? $this->inboxRecData['subject'] : 'Bankovní výpis',
			'source' => TableIssues::msAPI,
			'section' => $sectionNdx, 'issueKind' => $issueKindNdx,
			'docState' => 1000, 'docStateMain' => 0,
		];

		$issue = ['recData' => $issueRecData];

		foreach ($this->inboxAttachments as $attFileName)
			$issue['attachments'][] = ['fullFileName' => $attFileName];

		$this->inboxNdx = $tableIssues->addIssue($issue);
	}

	protected function updateInbox ($fields)
	{
		$this->db()->query('UPDATE [wkf_core_issues] SET ', $fields, ' WHERE ndx = %i', $this->inboxNdx);
		if (isset($fields['docState']))
		{
			$tableIssues = $this->app->table('wkf.core.issues');
			$tableIssues->docsLog($this->inboxNdx);
		}
	}

	protected function saveToInbox_addPersons ($linkId, $persons)
	{
		forEach ($persons as $personNdx)
		{
			$newLink = ['linkId' => $linkId,
				'srcTableId' => 'e10pro.wkf.messages', 'srcRecId' => $this->inboxNdx,
				'dstTableId' => 'e10.persons.persons', 'dstRecId' => $personNdx];
			$this->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
		}
	}

	protected function saveToInbox_addNotify ()
	{
		if (!isset ($this->bankAccountLinkedPersons['e10-bankaccount-notify-statement']))
			return;

		foreach ($this->bankAccountLinkedPersons['e10-bankaccount-notify-statement'] as $ban)
		{
			$newLink = ['linkId' => 'e10pro-wkf-message-notify',
				'srcTableId' => 'e10pro.wkf.messages', 'srcRecId' => $this->inboxNdx,
				'dstTableId' => $ban['dstTableId'], 'dstRecId' => $ban['dstRecId']];
			$this->db()->query('INSERT INTO [e10_base_doclinks] ', $newLink);
		}
	}

	protected function createBankDocument ()
	{
		if ($this->statementTextData === FALSE)
			return;

		$import = \E10Doc\Bank\createImportObject ($this->app, $this->statementTextData);
		if ($import)
		{
			$import->setInboxNdx($this->inboxNdx);
			$import->run ();

			$this->updateInbox(['docState' => 1200, 'docStateMain' => 1]);
		}
		else
		{
			$this->addMessage ("Soubor neodpovídá žádnému ze známých formátů bankovního výpisu.");
		}
	}
}

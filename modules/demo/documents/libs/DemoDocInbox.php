<?php

namespace demo\documents\libs;

use \e10\utils, \e10\Utility, wkf\core\TableIssues;

class DemoDocInbox extends DemoDocIssue
{
	var $disableDocLink = FALSE;

	public function createInbox ()
	{
		$issueKind = $this->tableIssues->defaultSystemKind (51, TRUE);
		$section = $this->tableIssues->defaultSection (51);

		$msgRecData = [
			'issueType' => TableIssues::mtInbox, 'issueKind' => $issueKind,
			'section' => $section,
			//'money' => $this->srcDocRec['toPay'], 'currency' => $this->srcDocRec['currency'],
			'subject' => 'Faktura '.$this->srcDocRec['symbol1'].': '.$this->srcDocRec['title'],
			'author' => $this->srcDocRec['author'],
			'dateIncoming' => $this->srcDocRec['dateIssue'],
			'dateCreate' => utils::createDateTimeFromTime($this->srcDocRec['dateIssue'], mt_rand(10, 23).':'.mt_rand(10, 59)),
			'docState' => 4000, 'docStateMain' => 2
		];

		if ($this->disableDocLink)
		{
			$msgRecData['docState'] = 1200;
			$msgRecData['docStateMain'] = 1;
			$msgRecData['dateCreate'] = utils::now();
			$msgRecData['dateIncoming'] = utils::today();
		}

		$this->newIssueNdx = $this->tableIssues->dbInsertRec ($msgRecData);
		$this->createInboxPersons('wkf-issues-from', [$this->srcDocRec['person']]);
		$this->createInboxPersons('wkf-issues-to', [$this->srcDocRec['owner'], $this->srcDocRec['author']]);

		if (!$this->disableDocLink)
			$this->createInboxDocLink ();

		if (!isset($this->app->params['demoFastMode']))
			$this->createPdfDocument ();

		$recData = $this->tableIssues->loadItem($this->newIssueNdx);
		$this->tableIssues->checkAfterSave2 ($recData); // TODO: dirty hack :-(
		$this->tableIssues->docsLog ($this->newIssueNdx);
	}

	protected function createInboxPersons ($linkId, $persons)
	{
		forEach ($persons as $personNdx)
		{
			$newLink = [
				'linkId' => $linkId,
				'srcTableId' => 'wkf.core.issues', 'srcRecId' => $this->newIssueNdx,
				'dstTableId' => 'e10.persons.persons', 'dstRecId' => $personNdx
			];
			$this->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
		}
	}

	protected function createInboxDocLink ()
	{
		$newLink = [
			'linkId' => 'e10docs-inbox',
			'srcTableId' => 'e10doc.core.heads', 'srcRecId' => $this->srcDocNdx,
			'dstTableId' => 'wkf.core.issues', 'dstRecId' => $this->newIssueNdx];
		$this->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
	}

	public function createPdfDocument ()
	{
		if ($this->srcDocRec['activity'] == 'balSetOff')
			$report = new BalSetOffReport ($this->tableDocHeads, $this->srcDocRec);
		else
			$report = new InvoiceInReport ($this->tableDocHeads, $this->srcDocRec);
		$report->init ();
		$report->renderReport ();
		$report->createReport ();

		\E10\Base\addAttachments ($this->app, 'wkf.core.issues', $this->newIssueNdx, $report->fullFileName, '', TRUE);
	}
}

<?php

namespace demo\documents\libs;

use \e10\utils, \e10\Utility, \wkf\core\TableIssues;


class DemoDocOutbox extends DemoDocIssue
{
	public function createOutbox ()
	{
		$report = $this->tableDocHeads->getReportData ('e10doc.invoicesOut.libs.InvoiceOutReport', $this->srcDocNdx);
		if (!isset($this->app->params['demoFastMode']))
		{
			$report->renderReport ();
			$report->createReport ();
		}

		$issueKind = $this->tableIssues->defaultSystemKind (121, TRUE);
		$section = $this->tableIssues->defaultSection (121);

		$msgRecData = [
			'issueType' => TableIssues::mtOutbox, 'issueKind' => $issueKind,
			'section' => $section,
			//'money' => $this->srcDocRec['toPay'], 'currency' => $this->srcDocRec['currency'],

			'subject' => $report->createReportPart ('emailSubject'),
			'text' => $report->createReportPart ('emailBody'),
			'author' => $this->srcDocRec['author'],
			'dateIncoming' => $this->srcDocRec['dateIssue'],
			'dateCreate' => utils::createDateTimeFromTime($this->srcDocRec['dateIssue'], mt_rand(10, 23).':'.mt_rand(10, 59)),
			'recNdx' => $this->srcDocNdx, 'tableNdx' => 1078,
			'docState' => 4000, 'docStateMain' => 2
		];

		$this->newIssueNdx = $this->tableIssues->dbInsertRec ($msgRecData);
		$this->createOutboxPersons('wkf-issues-to', [$this->srcDocRec['person']]);
		$this->createOutboxPersons('wkf-issues-from', [$this->srcDocRec['owner']]);

		if (!isset($this->app->params['demoFastMode']))
			\E10\Base\addAttachments ($this->app, 'wkf.core.issues', $this->newIssueNdx, $report->fullFileName, '', TRUE);
	}

	protected function createOutboxPersons ($linkId, $persons)
	{
		forEach ($persons as $personNdx)
		{
			$newLink = [
				'linkId' => $linkId,
				'srcTableId' => 'wkf.core.issues', 'srcRecId' => $this->newIssueNdx,
				'dstTableId' => 'e10.persons.persons', 'dstRecId' => $personNdx
			];
			$this->db()->query ('INSERT INTO e10_base_doclinks ', $newLink);
		}
	}
}

<?php

namespace Shipard\Report;
use \Shipard\Utils\Utils;
use \Shipard\Form\TableForm;


class SendFormReportWizard extends \Shipard\Form\Wizard
{
	public function __construct($app, $options = NULL)
	{
		parent::__construct($app, $options);
	}

	public function addParams()
	{
		foreach ($_GET as $param => $value)
		{
			if (substr($param, 0, 11) !== 'data-param-')
				continue;
			$this->recData[$param] = $value;
			$this->addInput($param, '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		}
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->sendMessage ();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		/** @var \e10\persons\TablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');

		$this->table = $this->app->table ('e10.witems.items');

		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$focusedPKPrimary = intval ($this->app()->testGetParam('focusedPKPrimary'));
		if ($focusedPKPrimary)
			$this->focusedPK = $focusedPKPrimary;

		$this->recData['documentNdx'] = $this->focusedPK;
		$this->recData['documentTable'] = $this->app->testGetParam('documentTable');
		$this->recData['reportClass'] = $this->app->testGetParam('reportClass');
		$this->addParams();

		$documentTable = $this->app()->table ($this->recData['documentTable']);
		$report = $documentTable->getReportData ($this->recData['reportClass'], $this->recData['documentNdx']);
		foreach ($this->recData as $param => $value)
		{
			if (substr($param, 0, 11) !== 'data-param-')
				continue;
			$report->setOutsideParam ($param, $value);
		}

		$this->recData['subject'] = $report->createReportPart ('emailSubject');
		$this->recData['text'] = $report->createReportPart ('emailBody');
		$this->recData['to'] = '';

		$item = $documentTable->loadItem ($this->recData['documentNdx']);
		$documentInfo = $documentTable->getRecordInfo ($item);
		if (isset($documentInfo['persons']['to']))
		{
			$this->recData['to'] = $tablePersons->loadEmailsForReport($documentInfo['persons']['to'], $this->recData['reportClass']);
		}
		elseif (isset($documentInfo['emails']['to']))
		{
			$this->recData['to'] = $documentInfo['emails']['to'];
		}

		$this->recData['emailFromAddress'] = $this->app->cfgItem ('options.core.ownerEmail');
		$this->recData['emailFromName'] = $this->app->cfgItem ('options.core.ownerFullName');
		if (isset($documentInfo['emailFromAddress']))
		{
			$this->recData['emailFromAddress'] = $documentInfo['emailFromAddress'];
			$this->recData['emailFromName'] = $documentInfo['emailFromName'];
		}

		$this->openForm ();
			$this->addInput('documentNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('documentTable', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('reportClass', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

			$this->addInput('emailFromAddress', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			$this->addInput('emailFromName', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

			$this->layoutOpen(TableForm::ltGrid);
				$this->addInput('subject', 'Předmět', self::INPUT_STYLE_STRING, TableForm::coColW12, 100);
				$this->addInput('to', 'Pro', self::INPUT_STYLE_STRING, TableForm::coColW12, 120);
				$this->addInputMemo('text', 'Text zprávy', TableForm::coColW12);
			$this->layoutClose();

		$this->closeForm ();
	}

	public function sendMessage ()
	{
		$this->send();
		$this->stepResult ['close'] = 1;
	}

	public function send ()
	{
		$documentTable = $this->app()->table ($this->recData['documentTable']);

		/** @var \Shipard\Report\FormReport */
		$report = $documentTable->getReportData ($this->recData['reportClass'], $this->recData['documentNdx']);
		foreach ($this->recData as $param => $value)
		{
			if (substr($param, 0, 11) !== 'data-param-')
				continue;
			$report->setOutsideParam ($param, $value);
		}

		$report->renderReport ();
		$report->createReport ();

		$msg = new MailMessage($this->app());

		$msg->setFrom ($this->recData['emailFromName'], $this->recData['emailFromAddress']);
		$msg->setTo($this->recData['to']);
		$msg->setSubject($this->recData['subject']);
		$msg->setBody($this->recData['text']);
		$msg->setDocument ($this->recData['documentTable'], $this->recData['documentNdx'], $report);
		$msg->outboxLinkId = $report->outboxLinkId;

		$attachmentFileName = utils::safeChars($report->createReportPart ('fileName'));
		if ($attachmentFileName === '')
			$attachmentFileName = 'priloha';

		$msg->addAttachment($report->fullFileName, $attachmentFileName.'.pdf', 'application/pdf');

		$this->addOtherReports($documentTable, $msg, $report);
		$report->addMessageAttachments($msg);

		$msg->sendMail();
		$msg->saveToOutbox();
		$report->reportWasSent($msg);
	}

	function addOtherReports($documentTable, $msg, $mainReport)
	{
		if ($this->recData['reportClass'] === 'e10doc.invoicesOut.libs.InvoiceOutReport')
		{
			$report = $documentTable->getReportData ('e10doc.core.libs.reports.DocReportISDoc', $this->recData['documentNdx']);
			foreach ($this->recData as $param => $value)
			{
				if (substr($param, 0, 11) !== 'data-param-')
					continue;
				$report->setOutsideParam ($param, $value);
			}
			$report->saveAs = 'isdoc-xml';
			$report->renderReport ();
			$report->createReport ();
			$attachmentFileName = utils::safeChars(trim($report->createReportPart ('fileName')));
			$report->saveReportAs();
			$msg->addAttachment($report->fullFileName, $attachmentFileName.'.isdoc', 'text/xml');
		}
	}

	public function createHeader ()
	{
		$hdr = array ();
		$hdr ['icon'] = 'system/iconEmail';

		$documentTable = $this->app()->table ($this->recData['documentTable']);
		$item = $documentTable->loadItem ($this->recData['documentNdx']);
		$documentInfo = $documentTable->getRecordInfo ($item);

		$title = $documentInfo['docID'].' / ';
		if (isset($documentInfo['docTypeName']))
			$title .= $documentInfo['docTypeName'];
		else
		if (isset($documentInfo['title']))
			$title .= $documentInfo['title'];

		$hdr ['info'][] = array ('class' => 'title', 'value' => $title);
		$hdr ['info'][] = array ('class' => 'info', 'value' => 'Odeslat emailem');

		return $hdr;
	}

	public function loadEmails ($persons)
	{
		if (!count($persons))
			return '';

		$sql = 'SELECT valueString FROM [e10_base_properties] where [tableid] = %s AND [recid] IN %in AND [property] = %s AND [group] = %s ORDER BY ndx';
		$emailsRows = $this->app()->db()->query ($sql, 'e10.persons.persons', $persons, 'email', 'contacts')->fetchPairs ();
		return implode (', ', $emailsRows);
	}
}
